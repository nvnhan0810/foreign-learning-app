<?php

namespace Flc\Identity\Application\Command;

use Flc\Shared\Application\Command;

final class DeleteAllowedEmail implements Command
{
    public function __construct(public readonly int $id) {}
}
