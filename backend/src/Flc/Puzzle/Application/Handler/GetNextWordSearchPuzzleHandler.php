<?php

namespace Flc\Puzzle\Application\Handler;

use Flc\Puzzle\Application\Query\GetNextWordSearchPuzzle;
use Flc\Puzzle\Domain\WordSearchGrader;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;

final class GetNextWordSearchPuzzleHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly Clock $clock,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetNextWordSearchPuzzle);

        $eligible = array_values(array_filter(
            $this->vocabularies->listForUser($query->userId),
            fn (UserVocabulary $v) => WordSearchGrader::isEligibleWord($v->word),
        ));

        if (count($eligible) < WordSearchGrader::MIN_WORDS) {
            return null;
        }

        $picked = $this->pickMany($eligible, WordSearchGrader::MAX_WORDS);
        if (count($picked) < WordSearchGrader::MIN_WORDS) {
            return null;
        }

        $words = array_map(
            function (UserVocabulary $vocabulary) {
                $clue = $this->primaryMeaning($vocabulary);

                return [
                    'vocabulary_id' => (int) $vocabulary->id,
                    'word' => strtolower(trim($vocabulary->word)),
                    'definition' => $clue['definition'],
                    'part_of_speech' => $clue['part_of_speech'],
                ];
            },
            $picked,
        );

        $puzzle = WordSearchGrader::build($words);
        if ($puzzle === null) {
            return null;
        }

        $cluesById = [];
        foreach ($words as $item) {
            $cluesById[$item['vocabulary_id']] = [
                'definition' => $item['definition'],
                'part_of_speech' => $item['part_of_speech'],
            ];
        }

        $puzzle['words'] = array_map(
            function (array $word) use ($cluesById) {
                $clue = $cluesById[$word['vocabulary_id']] ?? [];

                return [
                    'vocabulary_id' => $word['vocabulary_id'],
                    'word' => $word['word'],
                    'length' => $word['length'],
                    'definition' => $clue['definition'] ?? 'Find this word in the grid.',
                    'part_of_speech' => $clue['part_of_speech'] ?? null,
                ];
            },
            $puzzle['words'],
        );

        return array_merge($puzzle, [
            'mode' => 'word_search',
        ]);
    }

    /**
     * @return array{definition: string, part_of_speech: ?string}
     */
    private function primaryMeaning(UserVocabulary $vocabulary): array
    {
        $meaning = $vocabulary->meanings[0] ?? null;

        if (! is_array($meaning)) {
            return [
                'definition' => 'Find this word in the grid.',
                'part_of_speech' => null,
            ];
        }

        $definition = trim((string) ($meaning['definition'] ?? ''));

        return [
            'definition' => $definition !== '' ? $definition : 'Find this word in the grid.',
            'part_of_speech' => isset($meaning['part_of_speech'])
                ? (string) $meaning['part_of_speech']
                : null,
        ];
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     * @return list<UserVocabulary>
     */
    private function pickMany(array $vocabularies, int $count): array
    {
        $pool = $vocabularies;
        $picked = [];

        while ($pool !== [] && count($picked) < $count) {
            $choice = $this->pickWeighted($pool);
            if ($choice === null) {
                break;
            }
            $picked[] = $choice;
            $pool = array_values(array_filter(
                $pool,
                fn (UserVocabulary $item) => $item->id !== $choice->id,
            ));
        }

        return $picked;
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     */
    private function pickWeighted(array $vocabularies): ?UserVocabulary
    {
        if ($vocabularies === []) {
            return null;
        }

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
}
