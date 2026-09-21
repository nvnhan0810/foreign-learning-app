<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Command\InitializeSessionQuestions;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningQuestion;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Application\Config;
use RuntimeException;

final class InitializeSessionQuestionsHandler implements CommandHandler
{
    public function __construct(
        private readonly ListeningAssessmentRepository $assessments,
        private readonly MediaItemRepository $mediaItems,
        private readonly Config $config,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof InitializeSessionQuestions);

        $assessment = $this->assessments->findAssessmentForUser($command->assessmentId, $command->userId);

        if ($assessment === null) {
            throw new RuntimeException('Assessment not found.');
        }

        $mediaItem = $this->mediaItems->find($assessment->mediaItemId);

        if ($mediaItem === null) {
            throw new RuntimeException('Assessment has no media item.');
        }

        $config = $this->config->get("listening.assessments.{$assessment->type}");
        $sessionSize = (int) (is_array($config) ? ($config['question_count'] ?? $assessment->questionCount) : $assessment->questionCount);

        if ($sessionSize < 1) {
            throw new RuntimeException('Invalid session size.');
        }

        $bankCount = $this->assessments->questionBankCount($assessment->mediaItemId);

        if (! $mediaItem->isQuestionBankReady() || $bankCount < $sessionSize) {
            throw new RuntimeException('Question bank is not ready or too small.');
        }

        $selected = $this->assessments->pickRandomQuestions($assessment->mediaItemId, $sessionSize);
        $questionIds = array_map(static fn (ListeningQuestion $q) => $q->id, $selected);

        $this->assessments->updateAssessmentQuestions($assessment->id, $questionIds);

        return $questionIds;
    }
}
