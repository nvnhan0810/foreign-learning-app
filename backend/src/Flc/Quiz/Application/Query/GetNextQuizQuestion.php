<?php

namespace Flc\Quiz\Application\Query;

use Flc\Shared\Application\Query;

final class GetNextQuizQuestion implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $vocabularyId = null,
    ) {}
}
