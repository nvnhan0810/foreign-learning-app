<?php

namespace Flc\Media\Infrastructure\External;

use Illuminate\Support\Facades\Http;

/**
 * Resolve YouTube watch URL metadata (title / thumbnail) for Add-from-YouTube flows.
 */
class YouTubePreviewService
{
    public function __construct(
        private readonly YouTubeUrlParser $parser,
    ) {}

    /**
     * @return array{title: string, url: string, video_id: string, thumbnail_url: string, author_name: string|null}|null
     */
    public function preview(string $rawUrl): ?array
    {
        $videoId = $this->parser->extractVideoId($rawUrl);
        if ($videoId === null) {
            return null;
        }

        $watchUrl = "https://www.youtube.com/watch?v={$videoId}";
        $title = null;
        $author = null;

        $oembed = Http::timeout(10)->get('https://www.youtube.com/oembed', [
            'url' => $watchUrl,
            'format' => 'json',
        ]);

        if ($oembed->successful()) {
            $data = $oembed->json();
            if (is_array($data)) {
                $title = $this->cleanTitle((string) ($data['title'] ?? ''));
                $author = trim((string) ($data['author_name'] ?? '')) ?: null;
            }
        }

        if ($title === null || $title === '') {
            $title = 'YouTube video';
        }

        return [
            'title' => $title,
            'url' => $watchUrl,
            'video_id' => $videoId,
            'thumbnail_url' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'author_name' => $author,
        ];
    }

    private function cleanTitle(string $title): string
    {
        $cleaned = trim(preg_replace('/\s*[-–|]\s*YouTube(?: Music)?\s*$/iu', '', $title) ?? $title);
        if ($cleaned === '' || preg_match('/^(youtube|youtube music)$/iu', $cleaned)) {
            return '';
        }

        return $cleaned;
    }
}
