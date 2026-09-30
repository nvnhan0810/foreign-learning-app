<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMediaContentJob;
use App\Models\MediaItem;
use Flc\Media\Infrastructure\External\YouTubePreviewService;
use Flc\Media\Infrastructure\External\YouTubeTranscriptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(
        private readonly YouTubePreviewService $youtubePreview,
        private readonly YouTubeTranscriptService $youtubeTranscript,
    ) {}

    public function index(Request $request): Response
    {
        $items = $request->user()
            ->mediaItems()
            ->orderBy('next_listen_at')
            ->get()
            ->map(fn (MediaItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'frequency' => $item->frequency,
                'difficulty' => $item->difficulty,
                'difficulty_label' => $item->difficultyLabel(),
                'analysis_status' => $item->analysis_status,
                'source_id' => $item->source_id,
            ])
            ->values()
            ->all();

        return Inertia::render('Media/Index', [
            'items' => $items,
        ]);
    }

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

    public function storeYouTube(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:255'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'difficulty' => ['sometimes', 'in:beginner,intermediate,advanced'],
        ]);

        $preview = $this->youtubePreview->preview($data['url']);
        if ($preview === null) {
            return back()->withInput()->with('error', 'Invalid YouTube URL.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $preview['title'];
        }

        $item = $request->user()->mediaItems()->create([
            'title' => $title,
            'type' => MediaItem::TYPE_YOUTUBE,
            'url' => $preview['url'],
            'source_id' => $preview['video_id'],
            'language' => 'en',
            'frequency' => $data['frequency'] ?? 'weekly',
            'difficulty' => MediaItem::normalizeDifficulty($data['difficulty'] ?? null),
            'is_active' => true,
            'analysis_status' => MediaItem::ANALYSIS_PENDING,
        ]);

        ProcessMediaContentJob::dispatch($item->id);

        return redirect()
            ->route('user.home.media.show', $item)
            ->with('success', 'YouTube media saved. Analysis and question bank are being generated.');
    }

    public function show(Request $request, MediaItem $mediaItem): Response
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }

        $segments = $this->ensureTranscriptSegments($mediaItem);

        return Inertia::render('Media/Show', [
            'item' => [
                'id' => $mediaItem->id,
                'title' => $mediaItem->title,
                'type' => $mediaItem->type,
                'source_id' => $mediaItem->source_id,
                'url' => $mediaItem->url,
                'frequency' => $mediaItem->frequency,
                'difficulty' => $mediaItem->difficulty,
                'difficulty_label' => $mediaItem->difficultyLabel(),
                'transcript' => $mediaItem->transcript,
                'transcript_segments' => $segments,
                'analysis_status' => $mediaItem->analysis_status,
            ],
        ]);
    }

    public function updateTranscript(Request $request, MediaItem $mediaItem): RedirectResponse|JsonResponse
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'transcript' => ['nullable', 'string', 'max:100000'],
        ]);

        $transcript = isset($validated['transcript']) ? trim((string) $validated['transcript']) : null;
        if ($transcript === '') {
            $transcript = null;
        }

        $mediaItem->update([
            'transcript' => $transcript,
            // Manual edits are plain text — drop timed cues until captions are re-fetched.
            'transcript_segments' => null,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'transcript' => $mediaItem->transcript,
                    'transcript_segments' => null,
                ],
                'message' => 'Transcript saved.',
            ]);
        }

        return redirect()
            ->route('user.home.media.show', $mediaItem)
            ->with('success', 'Transcript saved.');
    }

    /**
     * @return list<array{start: float, end: float, text: string}>|null
     */
    private function ensureTranscriptSegments(MediaItem $mediaItem): ?array
    {
        $existing = $this->normalizeSegments($mediaItem->transcript_segments);
        if ($existing !== null) {
            return $existing;
        }

        if ($mediaItem->type !== MediaItem::TYPE_YOUTUBE || ! $mediaItem->source_id) {
            return null;
        }

        try {
            $timed = $this->youtubeTranscript->fetch(
                $mediaItem->source_id,
                $mediaItem->language ?? 'en',
            );
        } catch (\Throwable) {
            return null;
        }

        if ($timed === null || ! $timed->hasSegments()) {
            return null;
        }

        $updates = [
            'transcript_segments' => $timed->segments,
        ];

        if ($mediaItem->transcript === null || trim($mediaItem->transcript) === '') {
            $updates['transcript'] = $timed->text;
            $mediaItem->transcript = $timed->text;
        }

        $mediaItem->update($updates);
        $mediaItem->transcript_segments = $timed->segments;

        return $timed->segments;
    }

    /**
     * @return list<array{start: float, end: float, text: string}>|null
     */
    private function normalizeSegments(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $segments = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = isset($item['text']) ? trim((string) $item['text']) : '';
            if ($text === '' || ! isset($item['start'], $item['end'])) {
                continue;
            }

            $segments[] = [
                'start' => (float) $item['start'],
                'end' => (float) $item['end'],
                'text' => $text,
            ];
        }

        return $segments === [] ? null : $segments;
    }

    public function audio(Request $request, MediaItem $mediaItem): StreamedResponse|JsonResponse
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }

        if (! $mediaItem->audio_path) {
            return response()->json(['message' => 'No audio file stored for this media item.'], 404);
        }

        $disk = Storage::disk($mediaItem->audio_disk);

        if (! $disk->exists($mediaItem->audio_path)) {
            return response()->json(['message' => 'Audio file not found.'], 404);
        }

        return $disk->response($mediaItem->audio_path);
    }

    public function destroy(Request $request, MediaItem $mediaItem): RedirectResponse
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($mediaItem->audio_path) {
            Storage::disk($mediaItem->audio_disk)->delete($mediaItem->audio_path);
        }

        $mediaItem->delete();

        return redirect()
            ->route('user.home.media')
            ->with('success', 'Media removed.');
    }
}
