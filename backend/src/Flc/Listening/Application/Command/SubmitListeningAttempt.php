<?php

namespace Flc\Listening\Application\Command;

use Flc\Shared\Application\Command;

final class SubmitListeningAttempt implements Command
{
    /**
     * @param  list<array{question_id: int, answer: string}>  $answers
     */
    public function __construct(
        public readonly int $assessmentId,
        public readonly int $userId,
        public readonly array $answers,
        public readonly ?string $startedAt = null,
        public readonly bool $strict = true,
    ) {}
}
