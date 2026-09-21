<?php

namespace Flc\Puzzle\Domain;

final class WordSearchGrader
{
    public const GRID_SIZE = 8;

    public const MIN_WORDS = 4;

    public const MAX_WORDS = 5;

    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 8;

    public const HINT_VISIBLE_SECONDS = 10;

    public const HINT_COOLDOWN_SECONDS = 10;

    /** @var list<array{0: int, 1: int}> */
    private const DIRECTIONS = [
        [0, 1],
        [1, 0],
        [1, 1],
        [1, -1],
        [0, -1],
        [-1, 0],
        [-1, 1],
        [-1, -1],
    ];

    public static function isEligibleWord(string $word): bool
    {
        $normalized = strtolower(trim($word));
        $len = strlen($normalized);

        return $len >= self::MIN_LENGTH
            && $len <= self::MAX_LENGTH
            && (bool) preg_match('/^[a-z]+$/', $normalized);
    }

    /**
     * @param  list<array{vocabulary_id: int, word: string}>  $words
     * @return array{
     *     grid_size: int,
     *     grid: list<list<string>>,
     *     words: list<array{vocabulary_id: int, word: string, length: int}>,
     *     placements: list<array{vocabulary_id: int, word: string, cells: list<array{r: int, c: int}>}>
     * }|null
     */
    public static function build(array $words): ?array
    {
        $normalized = [];
        foreach ($words as $item) {
            $word = strtolower(trim((string) ($item['word'] ?? '')));
            $vocabularyId = (int) ($item['vocabulary_id'] ?? 0);
            if ($vocabularyId <= 0 || ! self::isEligibleWord($word)) {
                continue;
            }
            $normalized[$vocabularyId] = [
                'vocabulary_id' => $vocabularyId,
                'word' => $word,
            ];
        }

        $normalized = array_values($normalized);
        if (count($normalized) < self::MIN_WORDS) {
            return null;
        }

        usort($normalized, fn (array $a, array $b) => strlen($b['word']) <=> strlen($a['word']));
        $selected = array_slice($normalized, 0, self::MAX_WORDS);

        $size = self::GRID_SIZE;
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $grid = array_fill(0, $size, array_fill(0, $size, null));
            $placements = [];
            $failed = false;

            foreach ($selected as $item) {
                $placement = self::placeWord($grid, $item['word'], $item['vocabulary_id']);
                if ($placement === null) {
                    $failed = true;
                    break;
                }
                foreach ($placement['cells'] as $index => $cell) {
                    $grid[$cell['r']][$cell['c']] = $item['word'][$index];
                }
                $placements[] = $placement;
            }

            if ($failed) {
                continue;
            }

            $letters = range('a', 'z');
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if ($grid[$r][$c] === null) {
                        $grid[$r][$c] = $letters[random_int(0, 25)];
                    }
                }
            }

            return [
                'grid_size' => $size,
                'grid' => $grid,
                'words' => array_map(
                    fn (array $item) => [
                        'vocabulary_id' => $item['vocabulary_id'],
                        'word' => $item['word'],
                        'length' => strlen($item['word']),
                    ],
                    $selected,
                ),
                'placements' => $placements,
            ];
        }

        return null;
    }

    /**
     * @param  list<list<string|null>>  $grid
     * @return array{vocabulary_id: int, word: string, cells: list<array{r: int, c: int}>}|null
     */
    private static function placeWord(array &$grid, string $word, int $vocabularyId): ?array
    {
        $size = count($grid);
        $len = strlen($word);
        $directions = self::DIRECTIONS;
        $attempts = 80;

        for ($i = 0; $i < $attempts; $i++) {
            [$dr, $dc] = $directions[array_rand($directions)];
            $r = random_int(0, $size - 1);
            $c = random_int(0, $size - 1);
            $endR = $r + ($len - 1) * $dr;
            $endC = $c + ($len - 1) * $dc;
            if ($endR < 0 || $endR >= $size || $endC < 0 || $endC >= $size) {
                continue;
            }

            $cells = [];
            $fits = true;
            for ($k = 0; $k < $len; $k++) {
                $rr = $r + $k * $dr;
                $cc = $c + $k * $dc;
                $existing = $grid[$rr][$cc];
                if ($existing !== null && $existing !== $word[$k]) {
                    $fits = false;
                    break;
                }
                $cells[] = ['r' => $rr, 'c' => $cc];
            }

            if (! $fits) {
                continue;
            }

            return [
                'vocabulary_id' => $vocabularyId,
                'word' => $word,
                'cells' => $cells,
            ];
        }

        return null;
    }

    /**
     * @param  list<array{vocabulary_id: int, word: string, cells: list<array{r: int, c: int}>}>  $placements
     * @param  list<int>  $foundIds
     * @param  list<array{r?: int, c?: int}|array{0?: int, 1?: int}>  $cells
     * @return array{
     *     hit: bool,
     *     vocabulary_id: int|null,
     *     word: string|null,
     *     cells: list<array{r: int, c: int}>,
     *     found_ids: list<int>,
     *     finished: bool,
     *     won: bool
     * }|null
     */
    public static function applyFind(array $placements, array $foundIds, array $cells): ?array
    {
        $path = self::normalizeCells($cells);
        if ($path === null || count($path) < self::MIN_LENGTH) {
            return null;
        }

        if (! self::isStraightPath($path)) {
            return null;
        }

        $foundSet = [];
        foreach ($foundIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $foundSet[$id] = true;
            }
        }

        $pathKey = self::cellKey($path);
        $reverseKey = self::cellKey(array_reverse($path));

        foreach ($placements as $placement) {
            $vocabularyId = (int) ($placement['vocabulary_id'] ?? 0);
            if ($vocabularyId <= 0 || isset($foundSet[$vocabularyId])) {
                continue;
            }

            $placementCells = self::normalizeCells($placement['cells'] ?? []);
            if ($placementCells === null) {
                continue;
            }

            $placementKey = self::cellKey($placementCells);
            if ($placementKey !== $pathKey && $placementKey !== $reverseKey) {
                continue;
            }

            $foundSet[$vocabularyId] = true;
            $foundList = array_map('intval', array_keys($foundSet));
            sort($foundList);

            $finished = count($foundList) >= count($placements);

            return [
                'hit' => true,
                'vocabulary_id' => $vocabularyId,
                'word' => (string) ($placement['word'] ?? ''),
                'cells' => $placementCells,
                'found_ids' => $foundList,
                'finished' => $finished,
                'won' => $finished,
            ];
        }

        return [
            'hit' => false,
            'vocabulary_id' => null,
            'word' => null,
            'cells' => $path,
            'found_ids' => array_map('intval', array_keys($foundSet)),
            'finished' => false,
            'won' => false,
        ];
    }

    /**
     * Verify a path spells a word on a client-supplied grid (API).
     *
     * @param  list<list<string>>  $grid
     * @param  list<array{r?: int, c?: int}>  $cells
     */
    public static function pathSpellsWord(array $grid, array $cells, string $word): bool
    {
        $path = self::normalizeCells($cells);
        $word = strtolower(trim($word));
        if ($path === null || ! self::isEligibleWord($word) || ! self::isStraightPath($path)) {
            return false;
        }
        if (count($path) !== strlen($word)) {
            return false;
        }

        $forward = '';
        $size = count($grid);
        foreach ($path as $cell) {
            $r = $cell['r'];
            $c = $cell['c'];
            if ($r < 0 || $c < 0 || $r >= $size || $c >= $size) {
                return false;
            }
            $forward .= strtolower((string) ($grid[$r][$c] ?? ''));
        }

        $reverse = strrev($forward);

        return $forward === $word || $reverse === $word;
    }

    /**
     * Pick one cell from an unfound word to highlight as a hint.
     *
     * @param  list<array{vocabulary_id: int, word: string, cells: list<array{r: int, c: int}>}>  $placements
     * @param  list<int>  $foundIds
     * @param  list<array{r: int, c: int}>  $excludeCells
     * @return array{r: int, c: int, vocabulary_id: int}|null
     */
    public static function pickHintCell(
        array $placements,
        array $foundIds,
        ?int $preferredVocabularyId = null,
        array $excludeCells = [],
    ): ?array {
        $foundSet = [];
        foreach ($foundIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $foundSet[$id] = true;
            }
        }

        $candidates = self::hintCandidates($placements, $foundSet, $excludeCells);

        if ($preferredVocabularyId !== null && $preferredVocabularyId > 0 && ! isset($foundSet[$preferredVocabularyId])) {
            $preferred = array_values(array_filter(
                $candidates,
                fn (array $candidate) => $candidate['vocabulary_id'] === $preferredVocabularyId,
            ));
            if ($preferred !== []) {
                return self::pickHintCandidate($preferred);
            }
        }

        if ($candidates !== []) {
            return self::pickHintCandidate($candidates);
        }

        $lastExcluded = $excludeCells !== [] ? end($excludeCells) : null;
        if (! is_array($lastExcluded)) {
            return null;
        }

        $fallbackExclude = array_slice($excludeCells, 0, -1);
        $fallback = self::hintCandidates($placements, $foundSet, $fallbackExclude);

        return self::pickHintCandidate($fallback);
    }

    /**
     * @param  array<int, true>  $foundSet
     * @param  list<array{r: int, c: int}>  $excludeCells
     * @return list<array{r: int, c: int, vocabulary_id: int}>
     */
    private static function hintCandidates(array $placements, array $foundSet, array $excludeCells): array
    {
        $candidates = [];

        foreach ($placements as $placement) {
            $vocabularyId = (int) ($placement['vocabulary_id'] ?? 0);
            if ($vocabularyId <= 0 || isset($foundSet[$vocabularyId])) {
                continue;
            }

            $cells = self::normalizeCells($placement['cells'] ?? []);
            if ($cells === null) {
                continue;
            }

            foreach ($cells as $cell) {
                if (self::isExcludedHintCell($cell, $excludeCells)) {
                    continue;
                }

                $candidates[] = [
                    'r' => $cell['r'],
                    'c' => $cell['c'],
                    'vocabulary_id' => $vocabularyId,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  list<array{r: int, c: int, vocabulary_id: int}>  $candidates
     * @return array{r: int, c: int, vocabulary_id: int}|null
     */
    private static function pickHintCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * @param  array{r: int, c: int}  $cell
     * @param  list<array{r: int, c: int}>  $excludeCells
     */
    private static function isExcludedHintCell(array $cell, array $excludeCells): bool
    {
        foreach ($excludeCells as $excluded) {
            if (! is_array($excluded)) {
                continue;
            }
            if ((int) ($excluded['r'] ?? -1) === $cell['r'] && (int) ($excluded['c'] ?? -1) === $cell['c']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $cells
     * @return list<array{r: int, c: int}>|null
     */
    public static function normalizeCells(array $cells): ?array
    {
        $path = [];
        foreach ($cells as $cell) {
            if (! is_array($cell)) {
                return null;
            }
            $r = $cell['r'] ?? $cell[0] ?? null;
            $c = $cell['c'] ?? $cell[1] ?? null;
            if (! is_numeric($r) || ! is_numeric($c)) {
                return null;
            }
            $path[] = ['r' => (int) $r, 'c' => (int) $c];
        }

        return $path === [] ? null : $path;
    }

    /**
     * @param  list<array{r: int, c: int}>  $path
     */
    public static function isStraightPath(array $path): bool
    {
        $count = count($path);
        if ($count < 2) {
            return false;
        }

        $stepR = $path[1]['r'] - $path[0]['r'];
        $stepC = $path[1]['c'] - $path[0]['c'];

        if ($stepR === 0 && $stepC === 0) {
            return false;
        }
        if (abs($stepR) > 1 || abs($stepC) > 1) {
            return false;
        }

        for ($i = 1; $i < $count; $i++) {
            $dR = $path[$i]['r'] - $path[$i - 1]['r'];
            $dC = $path[$i]['c'] - $path[$i - 1]['c'];
            if ($dR !== $stepR || $dC !== $stepC) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{r: int, c: int}>  $path
     */
    private static function cellKey(array $path): string
    {
        return implode(';', array_map(
            fn (array $cell) => $cell['r'].','.$cell['c'],
            $path,
        ));
    }
}
