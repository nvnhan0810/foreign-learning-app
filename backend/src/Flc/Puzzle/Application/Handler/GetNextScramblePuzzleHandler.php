<?php

namespace Flc\Puzzle\Application\Handler;

use Flc\Puzzle\Application\Query\GetNextScramblePuzzle;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;

final class GetNextScramblePuzzleHandler implements QueryHandler
{
    private const MIN_LENGTH = 3;

    private const MAX_LENGTH = 14;

    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly Clock $clock,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetNextScramblePuzzle);

        $eligible = array_values(array_filter(
            $this->vocabularies->listForUser($query->userId),
            fn (UserVocabulary $v) => $this->isEligible($v->word),
        ));

        if ($eligible === []) {
            return null;
        }

        $target = $this->pickWeighted($eligible);

        if ($target === null) {
            return null;
        }

        $word = strtolower(trim($target->word));
        $scrambled = $this->scramble($word);
        $hint = $this->primaryMeaning($target);

        return [
            'vocabulary_id' => $target->id,
            'mode' => 'scramble',
            'scrambled' => $scrambled,
            'word_length' => strlen($word),
            'correct_word' => $word,
            'hint_definition' => $hint['definition'],
            'hint_part_of_speech' => $hint['part_of_speech'],
        ];
    }

    public static function isEligibleWord(string $word): bool
    {
        $normalized = strtolower(trim($word));

        if ($normalized === '') {
            return false;
        }

        $len = strlen($normalized);

        return $len >= self::MIN_LENGTH
            && $len <= self::MAX_LENGTH
            && (bool) preg_match('/^[a-z]+$/', $normalized);
    }

    private function isEligible(string $word): bool
    {
        return self::isEligibleWord($word);
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     */
    private function pickWeighted(array $vocabularies): ?UserVocabulary
    {
        $weights = [];
        $now = $this->clock->now();

        foreach ($vocabularies as $vocabulary) {
            $base = 1 / ($vocabulary->timesQuizzed + 1);
            $decay = 1.0;

            if ($vocabulary->lastQuizzedAt !== null) {
                $last = new \DateTimeImmutable($vocabulary->lastQuizzedAt);
                $hours = max(0, ($now->getTimestamp() - $last->getTimestamp()) / 3600);
                $decay = min(1.0, max(0.15, $hours / 24));
            }

            $weights[] = max(0.01, $base * $decay);
        }

        $total = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $total;
        $cumulative = 0.0;

        foreach ($vocabularies as $index => $vocabulary) {
            $cumulative += $weights[$index];
            if ($roll <= $cumulative) {
                return $vocabulary;
            }
        }

        return $vocabularies[0] ?? null;
    }

    private function scramble(string $word): string
    {
        $letters = str_split($word);
        $scrambled = $word;
        $attempts = 0;

        while ($scrambled === $word && $attempts < 20) {
            shuffle($letters);
            $scrambled = implode('', $letters);
            $attempts++;
        }

        return $scrambled;
    }

    /**
     * @return array{definition: string, part_of_speech: ?string}
     */
    private function primaryMeaning(UserVocabulary $vocabulary): array
    {
        $meaning = $vocabulary->meanings[0] ?? null;

        if (! is_array($meaning)) {
            return [
                'definition' => $vocabulary->word,
                'part_of_speech' => null,
            ];
        }

        $definition = trim((string) ($meaning['definition'] ?? ''));

        return [
            'definition' => $definition !== '' ? $definition : $vocabulary->word,
            'part_of_speech' => isset($meaning['part_of_speech'])
                ? (string) $meaning['part_of_speech']
                : null,
        ];
    }
}
