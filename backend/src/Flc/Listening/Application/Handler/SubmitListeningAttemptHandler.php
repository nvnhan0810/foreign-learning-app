<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Command\SubmitListeningAttempt;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Listening\Domain\ListeningQuestion;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SubmitListeningAttemptHandler implements CommandHandler
{
    public function __construct(
        private readonly ListeningAssessmentRepository $assessments,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SubmitListeningAttempt);

        $assessment = $this->assessments->findAssessmentForUser($command->assessmentId, $command->userId);

        if ($assessment === null) {
            throw new AccessDeniedHttpException();
        }

        if ($assessment->status !== ListeningAssessment::STATUS_READY) {
            throw new RuntimeException('Assessment is not ready yet.');
        }

        $questions = $this->assessments->questionsForAssessment($assessment);

        /** @var array<int, ListeningQuestion> $questionsById */
        $questionsById = [];
        foreach ($questions as $question) {
            $questionsById[$question->id] = $question;
        }

        $score = 0;
        $results = [];

        foreach ($command->answers as $answer) {
            $question = $questionsById[$answer['question_id']] ?? null;

            if ($question === null) {
                if ($command->strict) {
                    throw new UnprocessableEntityHttpException('Invalid question for this session.');
                }

                continue;
            }

            $isCorrect = self::isAnswerCorrect($question, $answer['answer']);

            if ($isCorrect) {
                $score++;
            }

            $results[] = [
                'question_id' => $question->id,
                'answer' => $answer['answer'],
                'correct' => $isCorrect,
                'correct_answer' => $question->correctAnswer,
                'explanation' => $question->explanation,
            ];
        }

        $total = count($questions);
        $percentage = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;

        $attempt = $this->assessments->recordAttempt([
            'listeningAssessmentId' => $assessment->id,
            'mediaItemId' => $assessment->mediaItemId,
            'type' => $assessment->type,
            'userId' => $command->userId,
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'answers' => $results,
            'startedAt' => $command->startedAt,
        ]);

        return [
            'attempt_id' => $attempt->id,
            'score' => $score,
            'total' => $total,
            'percentage' => $percentage,
            'passed' => $percentage >= self::passThreshold($assessment->type),
            'results' => $results,
        ];
    }

    private static function isAnswerCorrect(ListeningQuestion $question, string $answer): bool
    {
        $normalizedAnswer = self::normalize($answer);
        $normalizedCorrect = self::normalize($question->correctAnswer);

        if ($normalizedAnswer === $normalizedCorrect) {
            return true;
        }

        if ($question->questionType === ListeningQuestion::TYPE_TRUE_FALSE) {
            $answerIsTrue = in_array($normalizedAnswer, ['true', 't', 'yes', '1'], true);
            $correctIsTrue = in_array($normalizedCorrect, ['true', 't', 'yes', '1'], true);
            $answerIsFalse = in_array($normalizedAnswer, ['false', 'f', 'no', '0'], true);
            $correctIsFalse = in_array($normalizedCorrect, ['false', 'f', 'no', '0'], true);

            return ($answerIsTrue && $correctIsTrue) || ($answerIsFalse && $correctIsFalse);
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private static function passThreshold(string $type): int
    {
        return match ($type) {
            ListeningAssessment::TYPE_QUIZ => 60,
            ListeningAssessment::TYPE_TEST => 70,
            ListeningAssessment::TYPE_EXAM => 75,
            default => 70,
        };
    }
}
