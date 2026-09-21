<?php

namespace Flc\AdminSettings\Application\Command;

use Flc\Shared\Application\Command;

final class SetAppSetting implements Command
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
    ) {}
}
