<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Primary upstream: api.dictionaryapi.dev (Free Dictionary API).
 */
final class DictionaryApiDevSource implements DictionaryHttpSource
{
    use NormalizesDictionaryMeanings;

    public const ID = 'dictionaryapi.dev';

    public function id(): string
    {
        return self::ID;
    }

    public function fetch(string $normalizedWord): DictionarySourceResult
    {
        $url = rtrim((string) config(
            'flc.dictionary_upstreams.sources.dictionaryapi_dev.url',
            'https://api.dictionaryapi.dev/api/v2/entries/en'
        ), '/').'/'.$normalizedWord;

        try {
            $response = Http::timeout($this->timeoutSeconds())->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            return DictionarySourceResult::unavailable('timeout: '.$e->getMessage());
        } catch (\Throwable $e) {
            return DictionarySourceResult::unavailable('error: '.$e->getMessage());
        }

        return $this->mapResponse($normalizedWord, $response);
    }

    private function mapResponse(string $word, Response $response): DictionarySourceResult
    {
        if ($response->status() === 429) {
            return DictionarySourceResult::unavailable('rate_limited', rateLimited: true);
        }

        if ($response->serverError()) {
            return DictionarySourceResult::unavailable('http_'.$response->status());
        }

        if ($response->status() === 404 || ! $response->successful()) {
            return DictionarySourceResult::notFound();
        }

        $entries = $response->json();
        if (! is_array($entries) || $entries === []) {
            return DictionarySourceResult::notFound();
        }

        $normalized = $this->normalizeEntries($word, $entries);
        if ($normalized === null) {
            return DictionarySourceResult::notFound();
        }

        return DictionarySourceResult::found($normalized);
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<string, mixed>|null
     */
    private function normalizeEntries(string $word, array $entries): ?array
    {
        $entry = $entries[0] ?? null;
        if (! is_array($entry)) {
            return null;
        }

        $meanings = [];
        $entrySynonyms = [];
        $entryAntonyms = [];

        foreach ($entry['meanings'] ?? [] as $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $partOfSpeech = is_string($meaning['partOfSpeech'] ?? null) ? $meaning['partOfSpeech'] : null;
            $meaningSynonyms = $this->stringList($meaning['synonyms'] ?? []);
            $meaningAntonyms = $this->stringList($meaning['antonyms'] ?? []);

            foreach ($meaning['definitions'] ?? [] as $definition) {
                if (! is_array($definition)) {
                    continue;
                }
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
                    'definition' => is_string($definition['definition'] ?? null) ? $definition['definition'] : '',
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

        if ($meanings === []) {
            return null;
        }

        $phonetic = is_string($entry['phonetic'] ?? null) ? $entry['phonetic'] : null;
        $audioUrl = null;
        $phonetics = $entry['phonetics'] ?? [];
        if (is_array($phonetics)) {
            foreach ($phonetics as $p) {
                if (! is_array($p)) {
                    continue;
                }
                if ($phonetic === null && ! empty($p['text']) && is_string($p['text'])) {
                    $phonetic = $p['text'];
                }
            }
            $audioUrl = $this->extractAudioUrl($phonetics);
        }

        return $this->entryPayload(
            $word,
            self::ID,
            $phonetic,
            $audioUrl,
            $meanings,
            array_keys($entrySynonyms),
            array_keys($entryAntonyms),
        );
    }

    /**
     * @param  array<int, mixed>  $phonetics
     */
    private function extractAudioUrl(array $phonetics): ?string
    {
        $candidates = [];
        foreach ($phonetics as $phonetic) {
            if (! is_array($phonetic)) {
                continue;
            }
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
}
