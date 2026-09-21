<?php

namespace Flc\Identity\Application\Query;

use Flc\Shared\Application\Query;

final class ListAllowedEmails implements Query
{
    public function __construct(public readonly int $perPage = 20) {}
}
