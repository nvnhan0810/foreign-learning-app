<?php

namespace Flc\Puzzle\Domain;

final class WordleKeyboardBuilder
{
    public const DECOY_COUNT = 4;

    /**
     * @return array<string, int> letter => max uses per guess
     */
    public static function build(string $targetWord, ?int $decoyCount = null, ?int $seed = null): array
    {
        $target = strtolower(trim($targetWord));
        $letters = [];

        foreach (str_split($target) as $char) {
            $letters[$char] = ($letters[$char] ?? 0) + 1;
        }

        $decoyCount = $decoyCount ?? self::DECOY_COUNT;
        $pool = array_values(array_diff(range('a', 'z'), array_keys($letters)));

        if ($seed !== null) {
            usort($pool, static fn (string $a, string $b): int => (
                crc32($seed.':'.$a) <=> crc32($seed.':'.$b)
            ));
        } else {
            shuffle($pool);
        }

        foreach (array_slice($pool, 0, min($decoyCount, count($pool))) as $decoy) {
            $letters[$decoy] = 1;
        }

        ksort($letters);

        return $letters;
    }

    /**
     * @param  array<string, int>  $keyboard
     */
    public static function isGuessAllowed(string $guess, array $keyboard): bool
    {
        if (! WordleGrader::isValidGuess($guess)) {
            return false;
        }

        $used = [];
        foreach (str_split(strtolower(trim($guess))) as $char) {
            $used[$char] = ($used[$char] ?? 0) + 1;
        }

        foreach ($used as $char => $count) {
            if (($keyboard[$char] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }
}
