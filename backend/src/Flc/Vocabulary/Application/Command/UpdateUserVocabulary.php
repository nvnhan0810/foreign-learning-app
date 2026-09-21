<?php

namespace Flc\Vocabulary\Application\Command;

use Flc\Shared\Application\Command;

final class UpdateUserVocabulary implements Command
{
    /**
     * @param  array{phonetic?: ?string, meanings?: list<array<string, mixed>>}  $data
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $vocabularyId,
        public readonly array $data,
    ) {}
}
