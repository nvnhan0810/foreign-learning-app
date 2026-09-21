<?php

namespace Flc\Quiz\Application\Handler;

use Flc\Quiz\Application\Query\GetNextQuizQuestion;
use Flc\Shared\Application\Clock;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;

final class GetNextQuizQuestionHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly Clock $clock,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetNextQuizQuestion);

        $vocabularies = $this->vocabularies->listForUser($query->userId);

        if (count($vocabularies) < 4) {
            return null;
        }

        if ($query->vocabularyId !== null) {
            $target = $this->findVocabulary($vocabularies, $query->vocabularyId);
            if ($target !== null) {
                return $this->standardQuestion($target, $vocabularies);
            }
        }

        $target = $this->pickWeighted($vocabularies);

        if ($target === null) {
            return null;
        }

        return $this->standardQuestion($target, $vocabularies);
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     * @return array<string, mixed>
     */
    private function standardQuestion(UserVocabulary $target, array $vocabularies): array
    {
        $questionType = random_int(0, 1) === 0 ? 'definition_to_word' : 'word_to_definition';
        $correctDefinition = $this->primaryDefinition($target);

        if ($questionType === 'definition_to_word') {
            $options = $this->buildWordOptions($vocabularies, $target);
            $prompt = $correctDefinition;
            $correctAnswer = $target->word;
        } else {
            $options = $this->buildDefinitionOptions($vocabularies, $target);
            $prompt = $target->word;
            $correctAnswer = $correctDefinition;
        }

        shuffle($options);

        return [
            'vocabulary_id' => $target->id,
            'question_type' => $questionType,
            'prompt' => $prompt,
            'options' => $options,
            'correct_answer' => $correctAnswer,
        ];
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     */
    private function findVocabulary(array $vocabularies, int $vocabularyId): ?UserVocabulary
    {
        foreach ($vocabularies as $vocabulary) {
            if ($vocabulary->id === $vocabularyId) {
                return $vocabulary;
            }
        }

        return null;
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

    private function primaryDefinition(UserVocabulary $vocabulary): string
    {
        return $vocabulary->meanings[0]['definition'] ?? $vocabulary->word;
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     * @return list<string>
     */
    private function buildWordOptions(array $vocabularies, UserVocabulary $target): array
    {
        $others = array_values(array_filter(
            $vocabularies,
            fn (UserVocabulary $v) => $v->id !== $target->id
        ));
        shuffle($others);

        $distractors = array_map(
            fn (UserVocabulary $v) => $v->word,
            array_slice($others, 0, 3)
        );

        return array_values(array_unique([$target->word, ...$distractors]));
    }

    /**
     * @param  list<UserVocabulary>  $vocabularies
     * @return list<string>
     */
    private function buildDefinitionOptions(array $vocabularies, UserVocabulary $target): array
    {
        $correct = $this->primaryDefinition($target);
        $others = array_values(array_filter(
            $vocabularies,
            fn (UserVocabulary $v) => $v->id !== $target->id
        ));
        shuffle($others);

        $distractors = array_map(
            fn (UserVocabulary $v) => $this->primaryDefinition($v),
            array_slice($others, 0, 3)
        );

        return array_values(array_unique([$correct, ...$distractors]));
    }
}
