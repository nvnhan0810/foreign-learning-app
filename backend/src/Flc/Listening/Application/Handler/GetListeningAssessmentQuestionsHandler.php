<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Query\GetListeningAssessmentQuestions;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GetListeningAssessmentQuestionsHandler implements QueryHandler
{
    public function __construct(
        private readonly ListeningAssessmentRepository $assessments,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetListeningAssessmentQuestions);

        $assessment = $this->assessments->findAssessmentForUser($query->assessmentId, $query->userId);

        if ($assessment === null) {
            throw new AccessDeniedHttpException();
        }

        if ($assessment->status !== ListeningAssessment::STATUS_READY) {
            return [
                'ready' => false,
                'status' => $assessment->status,
            ];
        }

        $questions = $this->assessments->questionsForAssessment($assessment);
        $formatted = StartListeningSessionHandler::formatQuestions($questions);

        return [
            'ready' => true,
            'assessment_id' => $assessment->id,
            'type' => $assessment->type,
            'title' => $assessment->title,
            'time_limit_minutes' => $assessment->timeLimitMinutes,
            'question_count' => count($formatted),
            'questions' => $formatted,
        ];
    }
}
