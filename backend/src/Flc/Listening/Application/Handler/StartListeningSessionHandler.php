<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Command\StartListeningSession;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Listening\Domain\ListeningQuestion;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Application\Config;
use RuntimeException;

final class StartListeningSessionHandler implements CommandHandler
{
    public function __construct(
        private readonly MediaItemRepository $mediaItems,
        private readonly ListeningAssessmentRepository $assessments,
        private readonly Config $config,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof StartListeningSession);

        return $this->start($command->mediaItemId, $command->userId, $command->type);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(int $mediaItemId, int $userId, string $type): array
    {
        $mediaItem = $this->mediaItems->find($mediaItemId);

        if ($mediaItem === null) {
            throw new RuntimeException('Media item not found.');
        }

        $config = $this->config->get("listening.assessments.{$type}");

        if (! is_array($config)) {
            throw new RuntimeException("Unknown assessment type: {$type}");
        }

        $sessionSize = (int) $config['question_count'];
        $bankCount = $this->assessments->questionBankCount($mediaItemId);

        if (! $mediaItem->isQuestionBankReady()) {
            throw new RuntimeException('Question bank is not ready yet.');
        }

        if ($bankCount < $sessionSize) {
            throw new RuntimeException("Not enough questions in bank (need {$sessionSize}, have {$bankCount}).");
        }

        $selected = $this->assessments->pickRandomQuestions($mediaItemId, $sessionSize);
        $questionIds = array_map(static fn (ListeningQuestion $q) => $q->id, $selected);

        $assessment = $this->assessments->createAssessment([
            'mediaItemId' => $mediaItemId,
            'userId' => $userId,
            'type' => $type,
            'title' => "{$mediaItem->title} — {$config['title_suffix']}",
            'description' => "Random {$type} session from question bank",
            'questionCount' => count($questionIds),
            'questionIds' => $questionIds,
            'timeLimitMinutes' => (int) $config['time_limit_minutes'],
            'status' => ListeningAssessment::STATUS_READY,
        ]);

        return [
            'assessment_id' => $assessment->id,
            'type' => $assessment->type,
            'title' => $assessment->title,
            'time_limit_minutes' => $assessment->timeLimitMinutes,
            'question_count' => count($questionIds),
            'questions' => self::formatQuestions($selected),
        ];
    }

    /**
     * @param  list<ListeningQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    public static function formatQuestions(array $questions): array
    {
        $formatted = [];

        foreach (array_values($questions) as $index => $question) {
            $formatted[] = [
                'id' => $question->id,
                'order' => $index + 1,
                'question_type' => $question->questionType,
                'prompt' => $question->prompt,
                'options' => $question->options,
                'audio_start_seconds' => $question->audioStartSeconds,
                'audio_end_seconds' => $question->audioEndSeconds,
            ];
        }

        return $formatted;
    }
}
