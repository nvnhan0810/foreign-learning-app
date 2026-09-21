<?php

namespace Flc\Listening\Application\Handler;

use Flc\Listening\Application\Query\GetListeningAttempts;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAttempt;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetListeningAttemptsHandler implements QueryHandler
{
    public function __construct(
        private readonly ListeningAssessmentRepository $assessments,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetListeningAttempts);

        $attempts = $this->assessments->listAttemptsForUser($query->assessmentId, $query->userId);

        return array_map(static fn (ListeningAttempt $attempt) => [
            'id' => $attempt->id,
            'score' => $attempt->score,
            'total' => $attempt->total,
            'percentage' => $attempt->percentage,
            'started_at' => $attempt->startedAt,
            'completed_at' => $attempt->completedAt,
        ], $attempts);
    }
}
