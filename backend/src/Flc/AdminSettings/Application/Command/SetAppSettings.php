<?php

namespace Flc\AdminSettings\Application\Command;

use Flc\Shared\Application\Command;

final class SetAppSettings implements Command
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(public readonly array $values) {}
}
