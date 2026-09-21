<?php

namespace Flc\Media\Infrastructure\External;

use Illuminate\Support\Facades\Http;

class YouTubeMetadataService
{
    /**
     * @return array{title: string, description: string, channel: string|null, video_id: string, url: string}|null
     */
    public function fetch(string $videoId): ?array
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get("https://www.youtube.com/watch?v={$videoId}");

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        $title = $this->metaContent($html, 'og:title')
            ?? $this->metaContent($html, 'twitter:title')
            ?? $this->extractJsonString($html, 'title');

        $description = $this->metaContent($html, 'og:description')
            ?? $this->metaContent($html, 'description')
            ?? $this->extractJsonString($html, 'shortDescription');

        $channel = $this->metaContent($html, 'og:site_name')
            ?? $this->extractJsonString($html, 'author');

        $title = $this->decode($title ?? '');
        $description = $this->decode($description ?? '');

        if ($title === '' && $description === '') {
            return null;
        }

        return [
            'title' => $title,
            'description' => $description,
            'channel' => $channel ? $this->decode($channel) : null,
            'video_id' => $videoId,
            'url' => "https://www.youtube.com/watch?v={$videoId}",
        ];
    }

    public function toContentText(array $metadata, string $fallbackTitle = ''): string
    {
        $parts = [];

        $title = trim($metadata['title'] ?? '') ?: $fallbackTitle;
        if ($title !== '') {
            $parts[] = "Title: {$title}";
        }

        if (! empty($metadata['channel'])) {
            $parts[] = 'Channel: '.$metadata['channel'];
        }

        if (! empty($metadata['description'])) {
            $parts[] = 'Description: '.$metadata['description'];
        }

        if (! empty($metadata['url'])) {
            $parts[] = 'URL: '.$metadata['url'];
        }

        return implode("\n\n", $parts);
    }

    private function metaContent(string $html, string $property): ?string
    {
        $patterns = [
            '/<meta\s+property="'.preg_quote($property, '/').'"\s+content="([^"]*)"/i',
            '/<meta\s+name="'.preg_quote($property, '/').'"\s+content="([^"]*)"/i',
            '/<meta\s+content="([^"]*)"\s+property="'.preg_quote($property, '/').'"/i',
            '/<meta\s+content="([^"]*)"\s+name="'.preg_quote($property, '/').'"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return trim($matches[1]) !== '' ? $matches[1] : null;
            }
        }

        return null;
    }

    private function extractJsonString(string $html, string $key): ?string
    {
        if (preg_match('/"'.preg_quote($key, '/').'":"((?:[^"\\\\]|\\\\.)*)"/', $html, $matches)) {
            return stripcslashes($matches[1]);
        }

        return null;
    }

    private function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }
}
