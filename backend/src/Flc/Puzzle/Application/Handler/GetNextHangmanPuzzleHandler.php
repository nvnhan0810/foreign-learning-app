<?php

namespace Flc\Puzzle\Application\Handler;

use Flc\Puzzle\Application\Query\GetNextHangmanPuzzle;
use Flc\Puzzle\Domain\HangmanGrader;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;

final class GetNextHangmanPuzzleHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly Clock $clock,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetNextHangmanPuzzle);

        $eligible = array_values(array_filter(
            $this->vocabularies->listForUser($query->userId),
            fn (UserVocabulary $v) => HangmanGrader::isEligibleWord($v->word),
        ));

        if ($eligible === []) {
            return null;
        }

        $target = $this->pickWeighted($eligible);
        if ($target === null) {
            return null;
        }

        $word = strtolower(trim($target->word));
        $clue = $this->primaryMeaning($target);

        return [
            'vocabulary_id' => $target->id,
            'mode' => 'hangman',
            'word_length' => strlen($word),
            'max_wrong' => HangmanGrader::MAX_WRONG,
            'correct_word' => $word,
            'mask' => HangmanGrader::mask($word, []),
            'clue_definition' => $clue['definition'],
            'clue_part_of_speech' => $clue['part_of_speech'],
        ];
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

    /**
     * @return array{definition: string, part_of_speech: ?string}
     */
    private function primaryMeaning(UserVocabulary $vocabulary): array
    {
        $meaning = $vocabulary->meanings[0] ?? null;

        if (! is_array($meaning)) {
            return [
                'definition' => 'Guess the word letter by letter.',
                'part_of_speech' => null,
            ];
        }

        $definition = trim((string) ($meaning['definition'] ?? ''));

        return [
            'definition' => $definition !== '' ? $definition : 'Guess the word letter by letter.',
            'part_of_speech' => isset($meaning['part_of_speech'])
                ? (string) $meaning['part_of_speech']
                : null,
        ];
    }
}
