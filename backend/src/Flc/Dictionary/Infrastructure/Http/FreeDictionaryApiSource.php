<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Secondary upstream: freedictionaryapi.com (Wiktionary).
 */
final class FreeDictionaryApiSource implements DictionaryHttpSource
{
    use NormalizesDictionaryMeanings;

    public const ID = 'freedictionaryapi.com';

    public function id(): string
    {
        return self::ID;
    }

    public function fetch(string $normalizedWord): DictionarySourceResult
    {
        $base = rtrim((string) config(
            'flc.dictionary_upstreams.sources.freedictionaryapi.url',
            'https://freedictionaryapi.com/api/v1/entries/en'
        ), '/');
        $url = $base.'/'.$normalizedWord;

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

        $payload = $response->json();
        if (! is_array($payload)) {
            return DictionarySourceResult::notFound();
        }

        $normalized = $this->normalizePayload($word, $payload);
        if ($normalized === null) {
            return DictionarySourceResult::notFound();
        }

        return DictionarySourceResult::found($normalized);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function normalizePayload(string $word, array $payload): ?array
    {
        $entries = $payload['entries'] ?? null;
        if (! is_array($entries) || $entries === []) {
            return null;
        }

        $meanings = [];
        $entrySynonyms = [];
        $entryAntonyms = [];
        $phonetic = null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $lang = $entry['language']['code'] ?? null;
            if (is_string($lang) && strtolower($lang) !== 'en') {
                continue;
            }

            $partOfSpeech = is_string($entry['partOfSpeech'] ?? null) ? $entry['partOfSpeech'] : null;
            $entryLevelSynonyms = $this->stringList($entry['synonyms'] ?? []);
            $entryLevelAntonyms = $this->stringList($entry['antonyms'] ?? []);

            if ($phonetic === null) {
                $phonetic = $this->firstIpa($entry['pronunciations'] ?? null);
            }

            foreach ($entry['senses'] ?? [] as $sense) {
                if (! is_array($sense)) {
                    continue;
                }
                $definition = is_string($sense['definition'] ?? null) ? $sense['definition'] : '';
                if ($definition === '') {
                    continue;
                }

                $examples = $this->stringList($sense['examples'] ?? []);
                $synonyms = array_values(array_unique([
                    ...$entryLevelSynonyms,
                    ...$this->stringList($sense['synonyms'] ?? []),
                ]));
                $antonyms = array_values(array_unique([
                    ...$entryLevelAntonyms,
                    ...$this->stringList($sense['antonyms'] ?? []),
                ]));

                $meanings[] = [
                    'part_of_speech' => $partOfSpeech,
                    'definition' => $definition,
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

            foreach ($entryLevelSynonyms as $term) {
                $entrySynonyms[$term] = true;
            }
            foreach ($entryLevelAntonyms as $term) {
                $entryAntonyms[$term] = true;
            }
        }

        if ($meanings === []) {
            return null;
        }

        return $this->entryPayload(
            $word,
            self::ID,
            $phonetic,
            null,
            $meanings,
            array_keys($entrySynonyms),
            array_keys($entryAntonyms),
        );
    }

    private function firstIpa(mixed $pronunciations): ?string
    {
        if (! is_array($pronunciations)) {
            return null;
        }
        foreach ($pronunciations as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $item['text'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }
}
