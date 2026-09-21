<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaContentJob;
use App\Models\MediaItem;
use Flc\Listening\Application\Query\GetListeningSessionOptions;
use Flc\Media\Infrastructure\External\YouTubePreviewService;
use Flc\Media\Infrastructure\External\YouTubeUrlParser;
use Flc\Media\Infrastructure\Storage\MediaStorageService;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListeningMediaController extends Controller
{
    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly YouTubeUrlParser $youtubeParser,
        private readonly YouTubePreviewService $youtubePreview,
        private readonly QueryBus $queries,
    ) {}

    /**
     * Resolve title / thumbnail from a YouTube URL (watch, youtu.be, shorts).
     */
    public function previewYouTube(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $preview = $this->youtubePreview->preview($data['url']);
        if ($preview === null) {
            return response()->json(['message' => 'Invalid YouTube URL.'], 422);
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Save YouTube URL or upload MP3 and start content analysis.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:youtube,mp3,audio'],
            'url' => ['required_if:type,youtube', 'nullable', 'url', 'max:2048'],
            'audio' => [
                Rule::requiredIf(in_array($request->input('type'), ['mp3', 'audio'], true)),
                'nullable',
                'file',
                'max:'.(config('listening.max_audio_size_mb') * 1024),
                'mimetypes:'.implode(',', config('listening.allowed_audio_mimes')),
            ],
            'language' => ['sometimes', 'string', 'max:10'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'notes' => ['nullable', 'string'],
            'transcript' => ['nullable', 'string'],
            'auto_process' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $type = $data['type'];
        $language = $data['language'] ?? 'en';
        $autoProcess = $data['auto_process'] ?? true;

        $payload = [
            'title' => $data['title'],
            'type' => $type,
            'language' => $language,
            'frequency' => $data['frequency'] ?? 'weekly',
            'difficulty' => MediaItem::normalizeDifficulty($data['difficulty'] ?? null),
            'notes' => $data['notes'] ?? null,
            'transcript' => $data['transcript'] ?? null,
            'is_active' => true,
            'analysis_status' => MediaItem::ANALYSIS_PENDING,
        ];

        if ($type === MediaItem::TYPE_YOUTUBE) {
            $videoId = $this->youtubeParser->extractVideoId($data['url']);

            if (! $videoId) {
                return response()->json(['message' => 'Invalid YouTube URL.'], 422);
            }

            $payload['url'] = "https://www.youtube.com/watch?v={$videoId}";
            $payload['source_id'] = $videoId;
        } else {
            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $request->file('audio');
            $stored = $this->storage->storeAudio($file, $user->id);

            $payload['url'] = $data['url'] ?? 'file://'.$stored['original_name'];
            $payload['audio_path'] = $stored['path'];
            $payload['audio_disk'] = $stored['disk'];
        }

        $item = $user->mediaItems()->create($payload);

        if ($autoProcess) {
            ProcessMediaContentJob::dispatch($item->id);
        }

        return response()->json([
            'data' => $this->formatMediaItem($item),
            'message' => $autoProcess
                ? 'Media saved. Analysis and question bank are being generated.'
                : 'Media saved.',
        ], 201);
    }

    public function show(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        return response()->json([
            'data' => $this->formatMediaItem($mediaItem->load('listeningAssessments')),
        ]);
    }

    public function updateTranscript(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        $validated = $request->validate([
            'transcript' => ['nullable', 'string', 'max:100000'],
        ]);

        $mediaItem->update([
            'transcript' => $validated['transcript'] ?? null,
        ]);

        return response()->json([
            'data' => $this->formatMediaItem($mediaItem->fresh()),
            'message' => 'Đã lưu transcript.',
        ]);
    }

    public function audio(Request $request, MediaItem $mediaItem): StreamedResponse|JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        if (! $mediaItem->audio_path) {
            return response()->json(['message' => 'No audio file stored for this media item.'], 404);
        }

        $disk = Storage::disk($mediaItem->audio_disk);

        if (! $disk->exists($mediaItem->audio_path)) {
            return response()->json(['message' => 'Audio file not found.'], 404);
        }

        return $disk->response($mediaItem->audio_path);
    }

    public function assessments(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        return response()->json([
            'data' => $this->queries->ask(new GetListeningSessionOptions($mediaItem->id)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMediaItem(MediaItem $item): array
    {
        $data = $item->toArray();

        if ($item->relationLoaded('listeningAssessments')) {
            $data['listening_assessments'] = $item->listeningAssessments;
        }

        return $data;
    }

    private function authorizeMedia(Request $request, MediaItem $mediaItem): void
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
