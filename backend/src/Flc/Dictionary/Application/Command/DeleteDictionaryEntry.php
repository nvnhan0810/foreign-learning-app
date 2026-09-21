<?php

namespace Flc\Dictionary\Application\Command;

use Flc\Shared\Application\Command;

final class DeleteDictionaryEntry implements Command
{
    public function __construct(public readonly string $word) {}
}
