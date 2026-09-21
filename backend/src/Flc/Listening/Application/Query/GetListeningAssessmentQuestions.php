<?php

namespace Flc\Listening\Application\Query;

use Flc\Shared\Application\Query;

final class GetListeningAssessmentQuestions implements Query
{
    public function __construct(
        public readonly int $assessmentId,
        public readonly int $userId,
    ) {}
}
