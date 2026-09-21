<?php

namespace Flc\Listening\Application\Command;

use Flc\Shared\Application\Command;

final class ResumeOrStartListeningSession implements Command
{
    public function __construct(
        public readonly int $mediaItemId,
        public readonly int $userId,
        public readonly string $type,
    ) {}
}
