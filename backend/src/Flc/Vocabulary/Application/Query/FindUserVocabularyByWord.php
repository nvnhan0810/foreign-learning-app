<?php

namespace Flc\Vocabulary\Application\Query;

use Flc\Shared\Application\Query;

final class FindUserVocabularyByWord implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly string $word,
    ) {}
}
