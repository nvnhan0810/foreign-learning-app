<?php

namespace Flc\Puzzle\Application\Handler;

use Flc\Puzzle\Application\Query\GetNextWordlePuzzle;
use Flc\Puzzle\Domain\WordleGrader;
use Flc\Puzzle\Domain\WordleKeyboardBuilder;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;

final class GetNextWordlePuzzleHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly Clock $clock,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetNextWordlePuzzle);

        $eligible = array_values(array_filter(
            $this->vocabularies->listForUser($query->userId),
            fn (UserVocabulary $v) => self::isEligibleWord($v->word),
        ));

        if ($eligible === []) {
            return null;
        }

        $exclude = [];
        foreach ($query->excludeVocabularyIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $exclude[$id] = true;
            }
        }

        $remaining = array_values(array_filter(
            $eligible,
            fn (UserVocabulary $v) => ! isset($exclude[(int) $v->id]),
        ));

        // Caller exhausted the cycle — allow a fresh pass over the full pool.
        if ($remaining === [] && $exclude !== []) {
            $remaining = $eligible;
        }

        $target = $this->pickWeighted($remaining);
        if ($target === null) {
            return null;
        }

        $word = strtolower(trim($target->word));

        return [
            'vocabulary_id' => $target->id,
            'mode' => 'wordle',
            'word_length' => WordleGrader::WORD_LENGTH,
            'max_guesses' => WordleGrader::MAX_GUESSES,
            'correct_word' => $word,
            'keyboard_letters' => WordleKeyboardBuilder::build($word, null, $target->id),
            'eligible_count' => count($eligible),
        ];
    }

    public static function isEligibleWord(string $word): bool
    {
        $normalized = strtolower(trim($word));

        return strlen($normalized) === WordleGrader::WORD_LENGTH
            && (bool) preg_match('/^[a-z]+$/', $normalized);
    }

    /**
     * Prefer under-practiced / not-recently-seen words, with randomness among peers.
     *
     * @param  list<UserVocabulary>  $vocabularies
     */
    private function pickWeighted(array $vocabularies): ?UserVocabulary
    {
        if ($vocabularies === []) {
            return null;
        }

        if (count($vocabularies) === 1) {
            return $vocabularies[0];
        }

        $now = $this->clock->now()->getTimestamp();
        $weights = [];

        foreach ($vocabularies as $vocabulary) {
            // Strongly prefer words practiced less often.
            $practice = 1.0 / ($vocabulary->timesQuizzed + 1);

            // Recency: never played >> played days ago >> played minutes ago.
            if ($vocabulary->lastQuizzedAt === null) {
                $recency = 12.0;
            } else {
                $last = (new \DateTimeImmutable($vocabulary->lastQuizzedAt))->getTimestamp();
                $hours = max(0.0, ($now - $last) / 3600);
                // ~0.05 right after play, ~1 after 12h, caps at 8 after ~4 days.
                $recency = min(8.0, max(0.05, $hours / 12));
            }

            $weights[] = max(0.001, $practice * $recency);
        }

        $total = array_sum($weights);
        if ($total <= 0) {
            return $vocabularies[array_rand($vocabularies)];
        }

        $roll = (mt_rand() / mt_getrandmax()) * $total;
        $cumulative = 0.0;

        foreach ($vocabularies as $index => $vocabulary) {
            $cumulative += $weights[$index];
            if ($roll <= $cumulative) {
                return $vocabulary;
            }
        }

        return $vocabularies[array_rand($vocabularies)];
    }
}
