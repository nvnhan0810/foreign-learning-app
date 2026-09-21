<?php

namespace Flc\Quiz\Application\Handler;

use Flc\Quiz\Application\Command\RecordQuizAttempt;
use Flc\Quiz\Application\Repository\QuizAttemptRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\WordChat\Application\LearningInsightRepository;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class RecordQuizAttemptHandler implements CommandHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly QuizAttemptRepository $attempts,
        private readonly LearningInsightRepository $insights,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof RecordQuizAttempt);

        $vocabulary = $this->vocabularies->findForUser($command->userId, $command->vocabularyId);

        if ($vocabulary === null) {
            throw new AccessDeniedHttpException();
        }

        $timesQuizzed = $this->attempts->record(
            $command->userId,
            $command->vocabularyId,
            $command->questionType,
            $command->correct,
        );

        if ($command->insightId !== null) {
            $insight = $this->insights->findForUser($command->userId, $command->insightId);
            if ($insight !== null) {
                $this->insights->incrementQuizUsage($command->insightId);
            }
        }

        return [
            'vocabulary_id' => $command->vocabularyId,
            'times_quizzed' => $timesQuizzed,
        ];
    }
}
