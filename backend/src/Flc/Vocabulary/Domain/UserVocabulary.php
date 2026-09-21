<?php

namespace Flc\Vocabulary\Domain;

final class UserVocabulary
{
    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  list<array<string, mixed>>  $examples
     */
    public function __construct(
        public ?int $id,
        public int $userId,
        public int $dictionaryEntryId,
        public string $word,
        public ?string $phonetic,
        public array $meanings,
        public array $examples = [],
        public int $timesQuizzed = 0,
        public ?string $lastQuizzedAt = null,
        public ?string $lastCorrectAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'dictionary_entry_id' => $this->dictionaryEntryId,
            'word' => $this->word,
            'phonetic' => $this->phonetic,
            'meanings' => $this->meanings,
            'times_quizzed' => $this->timesQuizzed,
            'last_quizzed_at' => $this->lastQuizzedAt,
            'last_correct_at' => $this->lastCorrectAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'examples' => $this->examples,
        ];
    }
}
