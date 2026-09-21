<?php

namespace Flc\Puzzle\Domain;

final class HangmanGrader
{
    public const MAX_WRONG = 6;

    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 12;

    public const HINT_VISIBLE_SECONDS = 10;

    public const HINT_COOLDOWN_SECONDS = 20;

    public static function isEligibleWord(string $word): bool
    {
        $normalized = strtolower(trim($word));
        $len = strlen($normalized);

        return $len >= self::MIN_LENGTH
            && $len <= self::MAX_LENGTH
            && (bool) preg_match('/^[a-z]+$/', $normalized);
    }

    public static function isValidLetter(string $letter): bool
    {
        return (bool) preg_match('/^[a-z]$/', strtolower(trim($letter)));
    }

    /**
     * @param  list<string>  $guessedLetters
     * @return list<string|null>
     */
    public static function mask(string $word, array $guessedLetters): array
    {
        $word = strtolower(trim($word));
        $guessed = array_fill_keys(
            array_map(fn (string $letter) => strtolower($letter), $guessedLetters),
            true,
        );

        $slots = [];
        for ($i = 0; $i < strlen($word); $i++) {
            $letter = $word[$i];
            $slots[] = isset($guessed[$letter]) ? $letter : null;
        }

        return $slots;
    }

    /**
     * @param  list<string>  $guessedLetters
     */
    public static function isWon(string $word, array $guessedLetters): bool
    {
        foreach (self::mask($word, $guessedLetters) as $slot) {
            if ($slot === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $guessedLetters
     */
    public static function wrongCount(string $word, array $guessedLetters): int
    {
        $word = strtolower(trim($word));
        $wrong = 0;

        foreach ($guessedLetters as $letter) {
            $letter = strtolower(trim((string) $letter));
            if ($letter === '' || ! self::isValidLetter($letter)) {
                continue;
            }

            if (! str_contains($word, $letter)) {
                $wrong++;
            }
        }

        return $wrong;
    }

    /**
     * Apply one letter guess. Returns null when the letter was already guessed.
     *
     * @param  list<string>  $guessedLetters
     * @return array{
     *     guessed_letters: list<string>,
     *     hit: bool,
     *     wrong_count: int,
     *     mask: list<string|null>,
     *     won: bool,
     *     lost: bool,
     *     finished: bool
     * }|null
     */
    public static function applyGuess(string $word, array $guessedLetters, string $letter): ?array
    {
        $word = strtolower(trim($word));
        $letter = strtolower(trim($letter));

        if (! self::isValidLetter($letter)) {
            return null;
        }

        $normalizedGuessed = [];
        foreach ($guessedLetters as $guessed) {
            $guessed = strtolower(trim((string) $guessed));
            if (self::isValidLetter($guessed) && ! in_array($guessed, $normalizedGuessed, true)) {
                $normalizedGuessed[] = $guessed;
            }
        }

        if (in_array($letter, $normalizedGuessed, true)) {
            return null;
        }

        $normalizedGuessed[] = $letter;
        $hit = str_contains($word, $letter);
        $wrong = self::wrongCount($word, $normalizedGuessed);
        $won = self::isWon($word, $normalizedGuessed);
        $lost = ! $won && $wrong >= self::MAX_WRONG;

        return [
            'guessed_letters' => $normalizedGuessed,
            'hit' => $hit,
            'wrong_count' => $wrong,
            'mask' => self::mask($word, $normalizedGuessed),
            'won' => $won,
            'lost' => $lost,
            'finished' => $won || $lost,
        ];
    }
}
