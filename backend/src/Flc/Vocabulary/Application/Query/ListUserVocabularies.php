<?php

namespace Flc\Vocabulary\Application\Query;

use Flc\Shared\Application\Query;

final class ListUserVocabularies implements Query
{
    public function __construct(public readonly int $userId) {}
}
