<?php

namespace Flc\Dictionary\Application\Query;

use Flc\Shared\Application\Query;

final class ResolveLookupWord implements Query
{
    public function __construct(
        public readonly string $word,
    ) {}
}
