<?php

namespace Flc\Puzzle\Domain;

final class WordleGrader
{
    public const MAX_GUESSES = 6;

    public const WORD_LENGTH = 5;

    public const HINT_VISIBLE_SECONDS = 10;

    /** Seconds from hint reveal until the help button can show the meaning again (includes visible window). */
    public const HINT_COOLDOWN_SECONDS = 20;

    /**
     * @return list<array{letter: string, state: 'correct'|'present'|'absent'}>
     */
    public static function grade(string $target, string $guess): array
    {
        $target = strtolower(trim($target));
        $guess = strtolower(trim($guess));
        $length = strlen($target);

        $states = array_fill(0, $length, 'absent');
        $remaining = [];

        for ($i = 0; $i < $length; $i++) {
            if ($guess[$i] === $target[$i]) {
                $states[$i] = 'correct';
            } else {
                $remaining[$target[$i]] = ($remaining[$target[$i]] ?? 0) + 1;
            }
        }

        for ($i = 0; $i < $length; $i++) {
            if ($states[$i] === 'correct') {
                continue;
            }

            $letter = $guess[$i];
            if (($remaining[$letter] ?? 0) > 0) {
                $states[$i] = 'present';
                $remaining[$letter]--;
            }
        }

        return array_map(
            fn (int $i) => [
                'letter' => $guess[$i],
                'state' => $states[$i],
            ],
            range(0, $length - 1),
        );
    }

    public static function isValidGuess(string $guess): bool
    {
        $normalized = strtolower(trim($guess));

        return strlen($normalized) === self::WORD_LENGTH
            && (bool) preg_match('/^[a-z]+$/', $normalized);
    }
}
