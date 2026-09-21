<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Flc\Dictionary\Application\RelatedWordsGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HttpDatamuseRelatedWordsGateway implements RelatedWordsGateway
{
    private const MAX = 15;

    public function fetch(string $normalizedWord): array
    {
        $word = trim($normalizedWord);
        if ($word === '' || str_contains($word, ' ')) {
            return ['synonyms' => [], 'antonyms' => []];
        }

        try {
            return [
                'synonyms' => $this->fetchRelation($word, 'rel_syn'),
                'antonyms' => $this->fetchRelation($word, 'rel_ant'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Datamuse related words failed', [
                'word' => $word,
                'error' => $e->getMessage(),
            ]);

            return ['synonyms' => [], 'antonyms' => []];
        }
    }

    /**
     * @return list<string>
     */
    private function fetchRelation(string $word, string $rel): array
    {
        $response = Http::timeout(5)->get('https://api.datamuse.com/words', [
            $rel => $word,
            'max' => self::MAX,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $items = $response->json();
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $term = trim((string) ($item['word'] ?? ''));
            if ($term === '' || strcasecmp($term, $word) === 0) {
                continue;
            }
            $out[$term] = $term;
        }

        return array_values($out);
    }
}
