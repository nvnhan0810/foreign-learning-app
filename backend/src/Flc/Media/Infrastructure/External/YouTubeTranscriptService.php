<?php

namespace Flc\Media\Infrastructure\External;

use Flc\Media\Domain\TimedTranscript;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubeTranscriptService
{
    private const ANDROID_USER_AGENT = 'com.google.android.youtube/20.10.38 (Linux; U; Android 11) gzip';

    private const WEB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const DEFAULT_SEGMENT_DURATION_SECONDS = 2.0;

    public function fetch(string $videoId, string $language = 'en'): ?TimedTranscript
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

    private function parseCaptions(string $body): ?TimedTranscript
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

    private function parseCaptionJson(string $json): ?TimedTranscript
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            return null;
        }

        /** @var list<array{start: float, end: float, text: string}> $segments */
        $segments = [];

        foreach ($data['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }

            $text = '';

            foreach ($event['segs'] ?? [] as $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                $text .= $segment['utf8'] ?? '';
            }

            $text = $this->normalizeCaptionText($text);

            if ($text === '') {
                continue;
            }

            $startMs = isset($event['tStartMs']) ? (float) $event['tStartMs'] : null;
            $durationMs = isset($event['dDurationMs']) ? (float) $event['dDurationMs'] : null;

            if ($startMs === null) {
                continue;
            }

            $start = $startMs / 1000.0;
            $end = $durationMs !== null
                ? $start + ($durationMs / 1000.0)
                : $start + self::DEFAULT_SEGMENT_DURATION_SECONDS;

            $segments[] = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];
        }

        return $this->finalizeSegments($segments);
    }

    private function parseCaptionVtt(string $vtt): ?TimedTranscript
    {
        /** @var list<array{start: float, end: float, text: string}> $segments */
        $segments = [];
        $lines = preg_split('/\R/', $vtt) ?: [];
        $pendingStart = null;
        $pendingEnd = null;
        $pendingText = [];

        $flush = function () use (&$segments, &$pendingStart, &$pendingEnd, &$pendingText): void {
            if ($pendingStart === null || $pendingEnd === null) {
                $pendingStart = null;
                $pendingEnd = null;
                $pendingText = [];

                return;
            }

            $text = $this->normalizeCaptionText(implode(' ', $pendingText));

            if ($text !== '') {
                $segments[] = [
                    'start' => $pendingStart,
                    'end' => $pendingEnd,
                    'text' => $text,
                ];
            }

            $pendingStart = null;
            $pendingEnd = null;
            $pendingText = [];
        };

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, 'WEBVTT') || str_starts_with($line, 'NOTE')) {
                $flush();

                continue;
            }

            if (preg_match('/^\d+$/', $line)) {
                continue;
            }

            if (preg_match('/^(\d{1,2}:)?\d{2}:\d{2}[.,]\d{3}\s+-->\s+(\d{1,2}:)?\d{2}:\d{2}[.,]\d{3}/', $line)) {
                $flush();
                $parts = preg_split('/\s+-->\s+/', $line) ?: [];
                $startRaw = $parts[0] ?? '';
                $endRaw = preg_replace('/\s+.*$/', '', $parts[1] ?? '') ?? '';
                $pendingStart = $this->parseVttTimestamp($startRaw);
                $pendingEnd = $this->parseVttTimestamp($endRaw);

                continue;
            }

            if ($pendingStart !== null) {
                $pendingText[] = $line;
            }
        }

        $flush();

        return $this->finalizeSegments($segments);
    }

    private function parseCaptionXml(string $xml): ?TimedTranscript
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadXML($xml);
        libxml_clear_errors();

        /** @var list<array{start: float, end: float, text: string}> $segments */
        $segments = [];

        foreach ($document->getElementsByTagName('text') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $text = html_entity_decode($node->textContent ?? '', ENT_QUOTES | ENT_HTML5);
            $text = $this->normalizeCaptionText($text);

            if ($text === '') {
                continue;
            }

            $startAttr = $node->getAttribute('start');
            $durAttr = $node->getAttribute('dur');

            if ($startAttr === '') {
                continue;
            }

            $start = (float) $startAttr;
            $end = $durAttr !== ''
                ? $start + (float) $durAttr
                : $start + self::DEFAULT_SEGMENT_DURATION_SECONDS;

            $segments[] = [
                'start' => $start,
                'end' => $end,
                'text' => $text,
            ];
        }

        return $this->finalizeSegments($segments);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    private function finalizeSegments(array $segments): ?TimedTranscript
    {
        if ($segments === []) {
            return null;
        }

        $normalized = [];

        foreach ($segments as $index => $segment) {
            $start = $segment['start'];
            $end = $segment['end'];
            $next = $segments[$index + 1] ?? null;

            if ($end <= $start) {
                $end = $next !== null
                    ? max($start + 0.01, $next['start'])
                    : $start + self::DEFAULT_SEGMENT_DURATION_SECONDS;
            }

            if ($next !== null && $end > $next['start']) {
                $end = $next['start'];
            }

            $normalized[] = [
                'start' => round($start, 3),
                'end' => round(max($start + 0.01, $end), 3),
                'text' => $segment['text'],
            ];
        }

        $text = implode(' ', array_map(
            static fn (array $segment): string => $segment['text'],
            $normalized,
        ));

        return new TimedTranscript($text, $normalized);
    }

    private function normalizeCaptionText(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '' || $text === '\n') {
            return '';
        }

        return $text;
    }

    private function parseVttTimestamp(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        if (! preg_match('/^(?:(\d{1,2}):)?(\d{2}):(\d{2})\.(\d{3})$/', $value, $matches)) {
            return null;
        }

        $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = (int) $matches[2];
        $seconds = (int) $matches[3];
        $millis = (int) $matches[4];

        return ($hours * 3600) + ($minutes * 60) + $seconds + ($millis / 1000.0);
    }
}
