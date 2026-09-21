<?php

namespace Flc\Media\Infrastructure\External;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeTranscriptService
{
    private const ANDROID_USER_AGENT = 'com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip';

    private const WEB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function fetch(string $videoId, string $language = 'en'): ?string
    {
        $tracks = $this->fetchCaptionTracksViaAndroidPlayer($videoId)
            ?? $this->fetchCaptionTracksFromWatchPage($videoId);

        if ($tracks === null) {
            return null;
        }

        $trackUrl = $this->selectTrackUrl($tracks, $language);

        if ($trackUrl === null) {
            return null;
        }

        $trackUrl = preg_replace('/&fmt=\w+$/', '', $trackUrl) ?? $trackUrl;

        $captionBody = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::ANDROID_USER_AGENT])
            ->get($trackUrl)
            ->body();

        $transcript = $this->parseCaptions($captionBody);

        if ($transcript === null) {
            Log::warning('YouTube caption track returned empty or unparsable content', [
                'video_id' => $videoId,
                'language' => $language,
            ]);
        }

        return $transcript;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchCaptionTracksViaAndroidPlayer(string $videoId): ?array
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'User-Agent' => self::ANDROID_USER_AGENT,
            ])
            ->post('https://www.youtube.com/youtubei/v1/player', [
                'context' => [
                    'client' => [
                        'clientName' => 'ANDROID',
                        'clientVersion' => '20.10.38',
                        'androidSdkVersion' => 30,
                        'hl' => 'en',
                    ],
                ],
                'videoId' => $videoId,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $tracks = $response->json('captions.playerCaptionsTracklistRenderer.captionTracks');

        return is_array($tracks) && $tracks !== [] ? $tracks : null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchCaptionTracksFromWatchPage(string $videoId): ?array
    {
        $pageHtml = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => self::WEB_USER_AGENT,
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->get("https://www.youtube.com/watch?v={$videoId}")
            ->body();

        $playerResponse = $this->extractInitialPlayerResponse($pageHtml);

        if ($playerResponse !== null) {
            $tracks = $playerResponse['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? null;

            if (is_array($tracks) && $tracks !== []) {
                return $tracks;
            }
        }

        if (! preg_match('/"captionTracks":(\[[\s\S]*?\])/', $pageHtml, $matches)) {
            return null;
        }

        $tracks = json_decode($matches[1], true);

        return is_array($tracks) && $tracks !== [] ? $tracks : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractInitialPlayerResponse(string $html): ?array
    {
        $marker = 'ytInitialPlayerResponse';
        $pos = strpos($html, $marker);

        if ($pos === false) {
            return null;
        }

        $equalsPos = strpos($html, '=', $pos);

        if ($equalsPos === false) {
            return null;
        }

        $start = strpos($html, '{', $equalsPos);

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $decoded = json_decode(substr($html, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function selectTrackUrl(array $tracks, string $language): ?string
    {
        foreach ($tracks as $track) {
            if (($track['languageCode'] ?? '') === $language && ! empty($track['baseUrl'])) {
                return $track['baseUrl'];
            }
        }

        foreach ($tracks as $track) {
            $code = $track['languageCode'] ?? '';

            if (str_starts_with($code, $language) && ! empty($track['baseUrl'])) {
                return $track['baseUrl'];
            }
        }

        foreach ($tracks as $track) {
            if (! empty($track['baseUrl'])) {
                return $track['baseUrl'];
            }
        }

        return null;
    }

    private function parseCaptions(string $body): ?string
    {
        if (trim($body) === '') {
            return null;
        }

        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, '{')) {
            return $this->parseCaptionJson($body);
        }

        if (str_starts_with($trimmed, 'WEBVTT') || str_contains($body, '-->')) {
            return $this->parseCaptionVtt($body);
        }

        return $this->parseCaptionXml($body);
    }

    private function parseCaptionJson(string $json): ?string
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        $segments = [];

        foreach ($data['events'] ?? [] as $event) {
            $text = '';

            foreach ($event['segs'] ?? [] as $segment) {
                $text .= $segment['utf8'] ?? '';
            }

            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            if ($text !== '' && $text !== '\n') {
                $segments[] = $text;
            }
        }

        if ($segments === []) {
            return null;
        }

        return implode(' ', $segments);
    }

    private function parseCaptionVtt(string $vtt): ?string
    {
        $segments = [];

        foreach (preg_split('/\R/', $vtt) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'WEBVTT') || str_contains($line, '-->')) {
                continue;
            }

            if (preg_match('/^\d+$/', $line)) {
                continue;
            }

            $segments[] = $line;
        }

        if ($segments === []) {
            return null;
        }

        return implode(' ', $segments);
    }

    private function parseCaptionXml(string $xml): ?string
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadXML($xml);
        libxml_clear_errors();

        $segments = [];

        foreach ($document->getElementsByTagName('text') as $node) {
            $text = html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5);
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

            if ($text !== '') {
                $segments[] = $text;
            }
        }

        if ($segments === []) {
            return null;
        }

        return implode(' ', $segments);
    }
}
