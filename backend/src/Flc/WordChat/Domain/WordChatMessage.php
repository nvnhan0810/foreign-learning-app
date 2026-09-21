<?php

namespace Flc\WordChat\Domain;

final class WordChatMessage
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $role,
        public string $content,
        public ?string $cursorRunId = null,
        public ?array $metadata = null,
        public ?string $createdAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'cursor_run_id' => $this->cursorRunId,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }
}
