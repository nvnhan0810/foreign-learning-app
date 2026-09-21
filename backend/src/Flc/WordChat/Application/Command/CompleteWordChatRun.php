<?php

namespace Flc\WordChat\Application\Command;

use Flc\Shared\Application\Command;

final class CompleteWordChatRun implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $cursorRunId,
        public readonly string $assistantContent,
    ) {}
}
