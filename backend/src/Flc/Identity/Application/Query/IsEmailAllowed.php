<?php

namespace Flc\Identity\Application\Query;

use Flc\Shared\Application\Query;

final class IsEmailAllowed implements Query
{
    public function __construct(public readonly string $email) {}
}
