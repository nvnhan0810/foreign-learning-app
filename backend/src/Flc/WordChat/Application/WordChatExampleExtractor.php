<?php

namespace Flc\WordChat\Application;

final class WordChatExampleExtractor
{
    /**
     * @return list<string>
     */
    public function extractFromTexts(string ...$texts): array
    {
        $examples = [];

        foreach ($texts as $text) {
            foreach ($this->extractFromText($text) as $example) {
                $examples[$example] = $example;
            }
        }

        return array_values($examples);
    }

    /**
     * @return list<string>
     */
    public function extractFromText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (preg_match('/more examples:\s*(.+)$/is', $text, $matches) === 1) {
            $text = trim((string) $matches[1]);
        }

        $examples = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $this->shouldSkipLine($line)) {
                continue;
            }

            $line = preg_replace('/^[-*•]\s+/u', '', $line) ?? $line;
            $line = preg_replace('/^\d+[.)]\s+/u', '', $line) ?? $line;
            $line = trim($line);

            if ($line === '' || $this->shouldSkipLine($line)) {
                continue;
            }

            if (preg_match('/^.+?[—–-]\s*(.+)$/u', $line, $matches) === 1) {
                $tail = trim((string) $matches[1]);
                if ($this->looksLikeExample($tail)) {
                    $examples[] = $this->normalizeExample($line);

                    continue;
                }
            }

            if ($this->looksLikeExample($line)) {
                $examples[] = $this->normalizeExample($line);
            }
        }

        return array_values(array_unique($examples));
    }

    private function shouldSkipLine(string $line): bool
    {
        return (bool) preg_match(
            '/^(tip:|note:|meaning:|definition:|more examples:|saved\b|yes\b|penal means\b)/iu',
            $line,
        );
    }

    private function looksLikeExample(string $line): bool
    {
        if (strlen($line) < 12) {
            return false;
        }

        if (! preg_match('/[.!?]$/u', $line)) {
            return false;
        }

        return (bool) preg_match('/^[A-Z0-9"\'(]/u', $line);
    }

    private function normalizeExample(string $line): string
    {
        $line = preg_replace('/\s+/u', ' ', trim($line)) ?? trim($line);

        return rtrim($line, '.!?').'.';
    }
}
