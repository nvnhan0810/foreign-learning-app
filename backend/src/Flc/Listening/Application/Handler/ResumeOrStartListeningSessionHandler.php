<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Command\ResumeOrStartListeningSession;
use Flc\Listening\Application\Command\StartListeningSession;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\CommandHandler;

final class ResumeOrStartListeningSessionHandler implements CommandHandler
{
    public function __construct(
        private readonly ListeningAssessmentRepository $assessments,
        private readonly CommandBus $commands,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ResumeOrStartListeningSession);

        $unfinished = $this->assessments->findUnfinishedAssessment(
            $command->mediaItemId,
            $command->userId,
            $command->type,
        );

        if ($unfinished !== null) {
            $questions = $this->assessments->questionsForAssessment($unfinished);

            return [
                'assessment_id' => $unfinished->id,
                'type' => $unfinished->type,
                'title' => $unfinished->title,
                'time_limit_minutes' => $unfinished->timeLimitMinutes,
                'question_count' => count($questions),
                'questions' => StartListeningSessionHandler::formatQuestions($questions),
                'resumed' => true,
            ];
        }

        return $this->commands->dispatch(new StartListeningSession(
            $command->mediaItemId,
            $command->userId,
            $command->type,
        ));
    }
}
