<?php

namespace Flc\WordChat\Application;

use Flc\WordChat\Domain\WordChatMessage;

interface WordChatMessageRepository
{
    public function save(WordChatMessage $message): WordChatMessage;

    public function findById(int $userId, int $id): ?WordChatMessage;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function updateMetadata(int $userId, int $id, array $metadata): void;

    /**
     * @return list<WordChatMessage>
     */
    public function listForUser(int $userId, ?int $beforeId = null, int $limit = 50): array;
}
