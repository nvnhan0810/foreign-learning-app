<?php

namespace Flc\Vocabulary\Application\Command;

use Flc\Shared\Application\Command;

final class DeleteUserVocabulary implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly int $vocabularyId,
    ) {}
}
