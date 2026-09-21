<?php

namespace Flc\Puzzle\Application\Query;

use Flc\Shared\Application\Query;

final class GetScrambleHint implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly int $vocabularyId,
    ) {}
}
