<?php

namespace Flc\Dictionary\Application;

use Flc\Dictionary\Domain\DictionaryEntry;
use InvalidArgumentException;

final class DictionaryMeaningsEditor
{
    public const MODE_FORM = 'form';

    public const MODE_JSON = 'json';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function fromFormRows(array $rows): array
    {
        $meanings = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $definition = trim((string) ($row['definition'] ?? ''));
            if ($definition === '') {
                continue;
            }

            $partOfSpeech = trim((string) ($row['part_of_speech'] ?? ''));

            $meanings[] = DictionaryEntry::normalizeMeaning([
                'part_of_speech' => $partOfSpeech !== '' ? $partOfSpeech : null,
                'definition' => $definition,
                'examples' => self::linesToList((string) ($row['examples_text'] ?? '')),
                'synonyms' => self::csvToList((string) ($row['synonyms_text'] ?? '')),
                'antonyms' => self::csvToList((string) ($row['antonyms_text'] ?? '')),
            ]);
        }

        return $meanings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fromJson(string $json): array
    {
        $trimmed = trim($json);
        if ($trimmed === '') {
            throw new InvalidArgumentException('JSON không hợp lệ: nội dung trống.');
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('JSON không hợp lệ.');
        }

        if (is_array($decoded) && array_is_list($decoded)) {
            $rawMeanings = $decoded;
        } elseif (is_array($decoded) && isset($decoded['meanings']) && is_array($decoded['meanings'])) {
            $rawMeanings = $decoded['meanings'];
        } else {
            throw new InvalidArgumentException('JSON phải là mảng meanings hoặc object { "meanings": [...] }.');
        }

        $meanings = DictionaryEntry::normalizeMeanings($rawMeanings);
        if ($meanings === []) {
            throw new InvalidArgumentException('Cần ít nhất một nghĩa có definition.');
        }

        return $meanings;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @return list<array{part_of_speech: string, definition: string, examples_text: string, synonyms_text: string, antonyms_text: string}>
     */
    public static function toFormRows(array $meanings): array
    {
        $rows = [];
        foreach (DictionaryEntry::normalizeMeanings($meanings) as $meaning) {
            $rows[] = [
                'part_of_speech' => (string) ($meaning['part_of_speech'] ?? ''),
                'definition' => (string) ($meaning['definition'] ?? ''),
                'examples_text' => implode("\n", DictionaryEntry::stringList($meaning['examples'] ?? [])),
                'synonyms_text' => implode(', ', DictionaryEntry::stringList($meaning['synonyms'] ?? [])),
                'antonyms_text' => implode(', ', DictionaryEntry::stringList($meaning['antonyms'] ?? [])),
            ];
        }

        if ($rows === []) {
            return [[
                'part_of_speech' => '',
                'definition' => '',
                'examples_text' => '',
                'synonyms_text' => '',
                'antonyms_text' => '',
            ]];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     */
    public static function toPrettyJson(array $meanings): string
    {
        $normalized = DictionaryEntry::normalizeMeanings($meanings);
        if ($normalized === []) {
            $normalized = [[
                'part_of_speech' => null,
                'definition' => '',
                'examples' => [],
                'synonyms' => [],
                'antonyms' => [],
            ]];
        }

        $payload = [];
        foreach ($normalized as $meaning) {
            $payload[] = [
                'part_of_speech' => $meaning['part_of_speech'] ?? null,
                'definition' => $meaning['definition'] ?? '',
                'examples' => DictionaryEntry::stringList($meaning['examples'] ?? []),
                'synonyms' => DictionaryEntry::stringList($meaning['synonyms'] ?? []),
                'antonyms' => DictionaryEntry::stringList($meaning['antonyms'] ?? []),
            ];
        }

        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function aiPrompt(string $word): string
    {
        $word = trim($word);
        $label = $word !== '' ? $word : '{WORD}';

        return <<<PROMPT
You are helping curate an English dictionary entry for FLC admin.

Word / phrase: {$label}

Return ONLY a valid JSON array (no markdown fences, no commentary) of meanings in this exact schema:

[
  {
    "part_of_speech": "adjective",
    "definition": "Feeling or showing pleasure",
    "examples": ["She looks happy today."],
    "synonyms": ["joyful", "glad"],
    "antonyms": ["sad", "unhappy"]
  }
]

Rules:
- Top-level MUST be a JSON array (or optionally {"meanings":[...]}).
- Each item MUST have a non-empty string "definition".
- "part_of_speech" is optional (noun, verb, adjective, adverb, phrase, idiom, ...). Use null or omit if unknown.
- "examples", "synonyms", "antonyms" MUST be arrays of strings. Use [] when empty.
- Prefer clear learner-friendly definitions. Vietnamese definitions are allowed when appropriate for FLC.
- Include multiple meanings when the word has distinct senses.
- Do not include extra keys (no "example" singular — use "examples").
PROMPT;
    }

    /**
     * @return list<string>
     */
    private static function linesToList(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function csvToList(string $text): array
    {
        $parts = preg_split('/[,;]+/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
