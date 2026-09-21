<?php

namespace Flc\WordChat\Application\Command;

use Flc\Shared\Application\Command;

final class CreateWordChatAgent implements Command
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
