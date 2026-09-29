<?php

namespace Flc\Dictionary\Infrastructure\Http;

/**
 * Shared helpers for normalizing upstream dictionary JSON into FLC shape.
 */
trait NormalizesDictionaryMeanings
{
    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  list<string>  $synonyms
     * @param  list<string>  $antonyms
     * @return array<string, mixed>
     */
    protected function entryPayload(
        string $word,
        string $source,
        ?string $phonetic,
        ?string $audioUrl,
        array $meanings,
        array $synonyms,
        array $antonyms,
    ): array {
        return [
            'word' => $word,
            'phonetic' => $phonetic,
            'audio_url' => $audioUrl,
            'meanings' => array_slice($meanings, 0, 12),
            'synonyms' => array_values(array_unique($synonyms)),
            'antonyms' => array_values(array_unique($antonyms)),
            'source' => $source,
            'curated' => false,
        ];
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    protected function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }

    protected function timeoutSeconds(): int
    {
        return max(1, (int) config('flc.dictionary_upstreams.timeout_seconds', 8));
    }
}
