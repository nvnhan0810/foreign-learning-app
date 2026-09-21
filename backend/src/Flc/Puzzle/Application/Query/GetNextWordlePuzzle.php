<?php

namespace Flc\Puzzle\Application\Query;

use Flc\Shared\Application\Query;

final class GetNextWordlePuzzle implements Query
{
    /**
     * @param  list<int>  $excludeVocabularyIds  Prefer skipping these until the eligible pool is exhausted.
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $excludeVocabularyIds = [],
    ) {}
}
