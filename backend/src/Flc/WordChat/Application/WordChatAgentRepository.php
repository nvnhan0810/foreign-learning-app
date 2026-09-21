<?php

namespace Flc\WordChat\Application;

interface WordChatAgentRepository
{
    public function findForUser(int $userId): ?array;

    /**
     * @return array{id: int, cursor_agent_id: string|null, status: string, prompt_version: int, error_message: string|null, updated_at: string|null}|null
     */
    public function findRecordForUser(int $userId): ?array;

    /**
     * @param  array{prompt_version?: int}  $record
     */
    public function needsPromptRefresh(array $record): bool;

    public function saveAgent(int $userId, string $cursorAgentId, string $status = 'active'): array;

    public function markCreating(int $userId): void;

    public function markReady(int $userId, string $cursorAgentId): void;

    public function markError(int $userId, string $message): void;

    public function markLastRun(int $userId): void;

    public function archiveForUser(int $userId): void;
}
