<?php

namespace Flc\Dictionary\Application;

use Flc\Shared\Support\Text;

final class LookupLemmaGenerator
{
    /**
     * @return list<string> Lowercase lemma candidates excluding the original word.
     */
    public function candidates(string $word): array
    {
        $word = Text::lower(trim($word));

        if ($word === '' || str_contains($word, ' ') || ! preg_match('/^[a-z]+$/', $word)) {
            return [];
        }

        $out = [];

        $add = function (string $candidate) use (&$out, $word): void {
            $candidate = Text::lower(trim($candidate));

            if ($candidate === '' || $candidate === $word) {
                return;
            }

            if (! preg_match('/^[a-z]+$/', $candidate) || strlen($candidate) < 2) {
                return;
            }

            $out[$candidate] = $candidate;
        };

        if (str_ends_with($word, 'ies') && strlen($word) > 4) {
            $add(substr($word, 0, -3).'y');
        }

        if (str_ends_with($word, 'es') && strlen($word) > 4) {
            $add(substr($word, 0, -2));
        }

        if (str_ends_with($word, 's') && ! str_ends_with($word, 'ss') && strlen($word) > 3) {
            $add(substr($word, 0, -1));
        }

        if (str_ends_with($word, 'ing') && strlen($word) > 5) {
            $stem = substr($word, 0, -3);
            $add($stem);
            $add($stem.'e');

            if (strlen($stem) >= 2 && $stem[-1] === $stem[-2]) {
                $add(substr($stem, 0, -1));
            }
        }

        if (str_ends_with($word, 'ed') && strlen($word) > 4) {
            $stem = substr($word, 0, -2);
            $add($stem);
            $add($stem.'e');

            if (strlen($stem) >= 2 && $stem[-1] === $stem[-2]) {
                $add(substr($stem, 0, -1));
            }
        }

        if (str_ends_with($word, 'ly') && strlen($word) > 4) {
            $add(substr($word, 0, -2));
        }

        return array_values($out);
    }
}
