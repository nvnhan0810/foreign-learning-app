<?php

namespace Flc\WordChat\Application\Query;

use Flc\Shared\Application\Query;

final class ListLearningInsights implements Query
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $word = null,
        public readonly int $limit = 50,
    ) {}
}
