<?php

namespace Flc\Listening\Application\Command;

use Flc\Shared\Application\Command;

final class InitializeSessionQuestions implements Command
{
    public function __construct(
        public readonly int $assessmentId,
        public readonly int $userId,
    ) {}
}
