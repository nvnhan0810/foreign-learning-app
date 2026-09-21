<?php

namespace Flc\Vocabulary\Application\Command;

use Flc\Shared\Application\Command;

final class SaveUserVocabulary implements Command
{
    /**
     * @param  list<array<string, mixed>>|null  $meanings
     * @param  list<string>|null  $examples
     * @param  list<string>|null  $synonyms
     * @param  list<string>|null  $antonyms
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $word,
        public readonly ?string $phonetic = null,
        public readonly ?array $meanings = null,
        public readonly ?array $examples = null,
        public readonly ?array $synonyms = null,
        public readonly ?array $antonyms = null,
    ) {}
}
