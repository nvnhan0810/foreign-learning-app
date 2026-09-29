<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Tertiary upstream: api.suvankar.cc (Wiktionary-backed).
 */
final class SuvankarDictionarySource implements DictionaryHttpSource
{
    use NormalizesDictionaryMeanings;

    public const ID = 'api.suvankar.cc';

    public function id(): string
    {
        return self::ID;
    }

    public function fetch(string $normalizedWord): DictionarySourceResult
    {
        $base = rtrim((string) config(
            'flc.dictionary_upstreams.sources.suvankar.url',
            'https://api.suvankar.cc/dictionaryapi/v1/definitions/en'
        ), '/');
        $url = $base.'/'.$normalizedWord.'?compact=true';

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
        $meaningsRaw = $payload['meanings'] ?? null;
        if (! is_array($meaningsRaw) || $meaningsRaw === []) {
            return null;
        }

        $meanings = [];
        $entrySynonyms = [];
        $entryAntonyms = [];

        foreach ($meaningsRaw as $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $partOfSpeech = is_string($meaning['partOfSpeech'] ?? null) ? $meaning['partOfSpeech'] : null;

            foreach ($meaning['senses'] ?? [] as $sense) {
                if (! is_array($sense)) {
                    continue;
                }

                $glosses = $this->stringList($sense['glosses'] ?? []);
                $definition = $glosses[0] ?? '';
                if ($definition === '' && is_string($sense['definition'] ?? null)) {
                    $definition = $sense['definition'];
                }
                if ($definition === '') {
                    continue;
                }

                $examples = $this->stringList($sense['examples'] ?? []);
                $synonyms = $this->stringList($sense['synonyms'] ?? []);
                $antonyms = $this->stringList($sense['antonyms'] ?? []);

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
        }

        if ($meanings === []) {
            return null;
        }

        return $this->entryPayload(
            $word,
            self::ID,
            null,
            null,
            $meanings,
            array_keys($entrySynonyms),
            array_keys($entryAntonyms),
        );
    }
}
