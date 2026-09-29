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
        $payload = self::toPromptMeaningsPayload($meanings);
        if ($payload === []) {
            $payload = [[
                'part_of_speech' => null,
                'definition' => '',
                'examples' => [],
                'synonyms' => [],
                'antonyms' => [],
            ]];
        }

        return (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param  list<string>  $learnerContext  Short notes (usage, meaning, comparison, etc.)
     * @param  list<array<string, mixed>>  $currentMeanings  Existing meanings to include in the prompt
     */
    public static function aiPrompt(
        string $word,
        array $learnerContext = [],
        array $currentMeanings = [],
    ): string {
        $word = trim($word);
        $label = $word !== '' ? $word : '{WORD}';
        $contextBlock = self::formatLearnerContextBlock($learnerContext);
        $currentBlock = self::formatCurrentMeaningsBlock($currentMeanings);

        return <<<PROMPT
You are helping curate an English dictionary entry for FLC.

Word / phrase: {$label}
{$currentBlock}
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
- Prefer clear learner-friendly English definitions.
- ALL string values in the JSON MUST be English only. Do NOT put Vietnamese (or any non-English language) in definition, examples, synonyms, antonyms, or part_of_speech.
- Include multiple meanings when the word has distinct senses.
- Do not include extra keys (no "example" singular — use "examples").
- Prefer one clear entry per distinct meaning; deduplicate overlapping senses.
- If Current meanings JSON is present, improve that JSON: fix gaps, add missing senses/examples/synonyms/antonyms, keep valid existing senses.
- If "Learner context from prior study" is present, synthesize that history into the JSON (usage, nuance, situations, comparisons). Fold useful points into definitions/examples — do not invent Vietnamese glosses.

{$contextBlock}
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     */
    private static function formatCurrentMeaningsBlock(array $meanings): string
    {
        $payload = self::toPromptMeaningsPayload($meanings);
        if ($payload === []) {
            return '';
        }

        $json = self::toPrettyJson($payload);

        return <<<BLOCK

Current meanings JSON (starting point — refine, do not discard useful senses without reason):
{$json}

BLOCK;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @return list<array{part_of_speech: mixed, definition: string, examples: list<string>, synonyms: list<string>, antonyms: list<string>}>
     */
    public static function toPromptMeaningsPayload(array $meanings): array
    {
        $payload = [];
        foreach (DictionaryEntry::normalizeMeanings($meanings) as $meaning) {
            $payload[] = [
                'part_of_speech' => $meaning['part_of_speech'] ?? null,
                'definition' => (string) ($meaning['definition'] ?? ''),
                'examples' => DictionaryEntry::stringList($meaning['examples'] ?? []),
                'synonyms' => DictionaryEntry::stringList($meaning['synonyms'] ?? []),
                'antonyms' => DictionaryEntry::stringList($meaning['antonyms'] ?? []),
            ];
        }

        return $payload;
    }

    /**
     * @param  list<string>  $learnerContext
     */
    private static function formatLearnerContextBlock(array $learnerContext): string
    {
        $notes = [];
        foreach ($learnerContext as $note) {
            if (! is_string($note)) {
                continue;
            }
            $trimmed = trim($note);
            if ($trimmed !== '') {
                $notes[] = '- '.$trimmed;
            }
        }

        if ($notes === []) {
            return "Learner context from prior study:\n(none)";
        }

        return "Learner context from prior study (synthesize into the JSON):\n".implode("\n", $notes);
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
