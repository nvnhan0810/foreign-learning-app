<?php

namespace Flc\Media\Application\Command;

use Flc\Shared\Application\Command;

final class ProcessMediaContent implements Command
{
    public function __construct(public readonly int $mediaItemId) {}
}
