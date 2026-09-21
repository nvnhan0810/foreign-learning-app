<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaContentJob;
use App\Models\ListenLog;
use App\Models\MediaItem;
use Flc\Media\Infrastructure\External\YouTubeUrlParser;
use Flc\Media\Infrastructure\Scheduling\MediaScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaItemController extends Controller
{
    public function __construct(
        private readonly MediaScheduleService $schedule,
        private readonly YouTubeUrlParser $youtubeParser,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->mediaItems()
            ->orderBy('next_listen_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'type' => ['required', 'in:audio,youtube'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $payload = [
            ...$data,
            'difficulty' => MediaItem::normalizeDifficulty($data['difficulty'] ?? null),
            'is_active' => $data['is_active'] ?? true,
            'next_listen_at' => $this->schedule->initialNextListenAt($data['frequency']),
        ];

        if ($data['type'] === MediaItem::TYPE_YOUTUBE) {
            $videoId = $this->youtubeParser->extractVideoId($data['url']);

            if ($videoId) {
                $payload['source_id'] = $videoId;
                $payload['analysis_status'] = MediaItem::ANALYSIS_PENDING;
            }
        }

        $item = $request->user()->mediaItems()->create($payload);

        if ($data['type'] === MediaItem::TYPE_YOUTUBE && $item->source_id) {
            ProcessMediaContentJob::dispatch($item->id);
        }

        return response()->json(['data' => $item], 201);
    }

    public function show(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        return response()->json(['data' => $mediaItem]);
    }

    public function update(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url', 'max:2048'],
            'type' => ['sometimes', 'in:audio,youtube'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['difficulty'])) {
            $data['difficulty'] = MediaItem::normalizeDifficulty($data['difficulty']);
        }

        $mediaItem->update($data);

        if (isset($data['frequency'])) {
            $mediaItem->next_listen_at = $this->schedule->initialNextListenAt($data['frequency']);
            $mediaItem->save();
        }

        return response()->json(['data' => $mediaItem->fresh()]);
    }

    public function destroy(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);
        $mediaItem->delete();

        return response()->json(['message' => 'Đã xóa media.']);
    }

    public function due(Request $request): JsonResponse
    {
        $items = $request->user()
            ->mediaItems()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_listen_at')
                    ->orWhere('next_listen_at', '<=', now());
            })
            ->orderBy('next_listen_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function listened(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        $data = $request->validate([
            'snooze_one_hour' => ['sometimes', 'boolean'],
        ]);

        ListenLog::query()->create([
            'media_item_id' => $mediaItem->id,
            'user_id' => $request->user()->id,
            'listened_at' => now(),
        ]);

        $this->schedule->markListened(
            $mediaItem,
            (bool) ($data['snooze_one_hour'] ?? false)
        );

        return response()->json(['data' => $mediaItem->fresh()]);
    }

    private function authorizeMedia(Request $request, MediaItem $mediaItem): void
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
