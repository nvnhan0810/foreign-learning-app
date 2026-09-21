<?php

namespace Flc\WordChat\Application\Command;

use Flc\Shared\Application\Command;

final class SendWordChatMessage implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $text,
    ) {}
}
