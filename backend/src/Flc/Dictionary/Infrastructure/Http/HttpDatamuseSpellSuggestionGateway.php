<?php

namespace Flc\Dictionary\Infrastructure\Http;

use Flc\Dictionary\Application\SpellSuggestionGateway;
use Flc\Shared\Support\Text;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class HttpDatamuseSpellSuggestionGateway implements SpellSuggestionGateway
{
    public function suggest(string $normalizedWord): ?string
    {
        $word = Text::lower(trim($normalizedWord));

        if ($word === '' || str_contains($word, ' ') || ! preg_match('/^[a-z]+$/', $word)) {
            return null;
        }

        try {
            $response = Http::timeout(3)->get('https://api.datamuse.com/words', [
                'sp' => $word,
                'max' => 5,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Datamuse spell suggestion failed', [
                'word' => $word,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $term = Text::lower(trim((string) ($item['word'] ?? '')));

            if ($term === '' || $term === $word) {
                continue;
            }

            if (! preg_match('/^[a-z]+$/', $term)) {
                continue;
            }

            return $term;
        }

        return null;
    }
}
