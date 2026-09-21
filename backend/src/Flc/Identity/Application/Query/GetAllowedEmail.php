<?php

namespace Flc\Identity\Application\Query;

use Flc\Shared\Application\Query;

final class GetAllowedEmail implements Query
{
    public function __construct(public readonly int $id) {}
}
