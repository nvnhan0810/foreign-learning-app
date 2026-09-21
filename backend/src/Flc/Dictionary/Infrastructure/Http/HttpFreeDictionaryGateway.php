<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Flc\Dictionary\Application\FreeDictionaryGateway;
use Illuminate\Support\Facades\Http;

final class HttpFreeDictionaryGateway implements FreeDictionaryGateway
{
    public function fetch(string $normalizedWord): ?array
    {
        $response = Http::timeout(10)->get(
            'https://api.dictionaryapi.dev/api/v2/entries/en/'.$normalizedWord
        );

        if (! $response->successful()) {
            return null;
        }

        $entries = $response->json();

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        return $this->normalizeEntries($normalizedWord, $entries);
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>
     */
    private function normalizeEntries(string $word, array $entries): array
    {
        $entry = $entries[0];
        $meanings = [];
        $entrySynonyms = [];
        $entryAntonyms = [];

        foreach ($entry['meanings'] ?? [] as $meaning) {
            $partOfSpeech = $meaning['partOfSpeech'] ?? null;
            $meaningSynonyms = $this->stringList($meaning['synonyms'] ?? []);
            $meaningAntonyms = $this->stringList($meaning['antonyms'] ?? []);

            foreach ($meaning['definitions'] ?? [] as $definition) {
                $examples = [];
                if (! empty($definition['example']) && is_string($definition['example'])) {
                    $examples[] = $definition['example'];
                }

                $definitionSynonyms = $this->stringList($definition['synonyms'] ?? []);
                $definitionAntonyms = $this->stringList($definition['antonyms'] ?? []);
                $synonyms = array_values(array_unique([...$meaningSynonyms, ...$definitionSynonyms]));
                $antonyms = array_values(array_unique([...$meaningAntonyms, ...$definitionAntonyms]));

                $meanings[] = [
                    'part_of_speech' => $partOfSpeech,
                    'definition' => $definition['definition'] ?? '',
                    'example' => $examples[0] ?? null,
                    'examples' => $examples,
                    'synonyms' => $synonyms,
                    'antonyms' => $antonyms,
                ];

                foreach ($synonyms as $term) {
                    $entrySynonyms[$term] = true;
                }
                foreach ($antonyms as $term) {
                    $entryAntonyms[$term] = true;
                }
            }

            foreach ($meaningSynonyms as $term) {
                $entrySynonyms[$term] = true;
            }
            foreach ($meaningAntonyms as $term) {
                $entryAntonyms[$term] = true;
            }
        }

        $phonetic = $entry['phonetic'] ?? null;
        $audioUrl = null;
        if (! empty($entry['phonetics'])) {
            foreach ($entry['phonetics'] as $p) {
                if (! empty($p['text']) && $phonetic === null) {
                    $phonetic = $p['text'];
                }
            }
            $audioUrl = $this->extractAudioUrl($entry['phonetics']);
        }

        return [
            'word' => $word,
            'phonetic' => $phonetic,
            'audio_url' => $audioUrl,
            'meanings' => array_slice($meanings, 0, 12),
            'synonyms' => array_keys($entrySynonyms),
            'antonyms' => array_keys($entryAntonyms),
            'source' => 'dictionaryapi.dev',
            'curated' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $phonetics
     */
    private function extractAudioUrl(array $phonetics): ?string
    {
        $candidates = [];
        foreach ($phonetics as $phonetic) {
            $audio = $phonetic['audio'] ?? null;
            if (is_string($audio) && $audio !== '') {
                $candidates[] = $audio;
            }
        }
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $audio) {
            if (str_contains($audio, '-us.') || str_contains($audio, '/us-')) {
                return $audio;
            }
        }

        return $candidates[0];
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function stringList(mixed $values): array
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
}
