<?php

namespace Flc\Quiz\Application\Command;

use Flc\Shared\Application\Command;

final class RecordQuizAttempt implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly int $vocabularyId,
        public readonly string $questionType,
        public readonly bool $correct,
        public readonly ?int $insightId = null,
    ) {}
}
