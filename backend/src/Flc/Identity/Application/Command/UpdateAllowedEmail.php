<?php

namespace Flc\Identity\Application\Command;

use Flc\Shared\Application\Command;

final class UpdateAllowedEmail implements Command
{
    public function __construct(
        public readonly int $id,
        public readonly string $pattern,
        public readonly ?string $label,
        public readonly bool $isActive,
    ) {}
}
