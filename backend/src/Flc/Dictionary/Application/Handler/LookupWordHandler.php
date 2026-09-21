<?php

namespace Flc\Dictionary\Application\Handler;

use Flc\Dictionary\Application\FreeDictionaryGateway;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\RelatedWordsGateway;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Shared\Support\Text;

final class LookupWordHandler implements QueryHandler
{
    public function __construct(
        private readonly DictionaryEntryRepository $entries,
        private readonly FreeDictionaryGateway $gateway,
        private readonly RelatedWordsGateway $relatedWords,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof LookupWord);

        $normalized = Text::lower(trim($query->word));
        if ($normalized === '') {
            return null;
        }

        $entry = $this->entries->findByWord($normalized);
        $payload = $entry !== null
            ? $entry->toClientPayload()
            : $this->gateway->fetch($normalized);

        if (! is_array($payload)) {
            return null;
        }

        return $this->enrichRelatedWords($payload, $normalized);
    }

    /**
     * Free Dictionary often omits synonyms/antonyms; fill from Datamuse so each meaning can show related words.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichRelatedWords(array $payload, string $word): array
    {
        // Curated FLC entries are authoritative — do not call external enrichment.
        if (! empty($payload['curated'])) {
            return $payload;
        }

        $synonyms = $this->collectTerms($payload, 'synonyms');
        $antonyms = $this->collectTerms($payload, 'antonyms');

        if ($synonyms === [] || $antonyms === []) {
            $related = $this->relatedWords->fetch($word);
            if ($synonyms === []) {
                $synonyms = DictionaryEntry::stringList($related['synonyms'] ?? []);
            }
            if ($antonyms === []) {
                $antonyms = DictionaryEntry::stringList($related['antonyms'] ?? []);
            }
        }

        $payload['synonyms'] = $synonyms;
        $payload['antonyms'] = $antonyms;

        // Keep related words on the first meaning so clients that only save `meanings` persist them.
        if (isset($payload['meanings']) && is_array($payload['meanings']) && $payload['meanings'] !== []) {
            $first = $payload['meanings'][0];
            if (is_array($first)) {
                if (DictionaryEntry::stringList($first['synonyms'] ?? []) === [] && $synonyms !== []) {
                    $first['synonyms'] = $synonyms;
                }
                if (DictionaryEntry::stringList($first['antonyms'] ?? []) === [] && $antonyms !== []) {
                    $first['antonyms'] = $antonyms;
                }
                $payload['meanings'][0] = $first;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function collectTerms(array $payload, string $key): array
    {
        $terms = DictionaryEntry::stringList($payload[$key] ?? []);
        foreach ($payload['meanings'] ?? [] as $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $terms = [...$terms, ...DictionaryEntry::stringList($meaning[$key] ?? [])];
        }

        return array_values(array_unique($terms));
    }
}
