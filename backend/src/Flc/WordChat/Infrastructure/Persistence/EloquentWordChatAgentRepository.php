<?php

namespace Flc\WordChat\Infrastructure\Persistence;

use App\Models\WordChatAgent as WordChatAgentModel;
use Flc\WordChat\Application\WordChatAgentRepository;

final class EloquentWordChatAgentRepository implements WordChatAgentRepository
{
    public function findForUser(int $userId): ?array
    {
        $model = WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->whereNotNull('cursor_agent_id')
            ->first();

        if ($model === null) {
            return null;
        }

        $record = [
            'id' => (int) $model->id,
            'cursor_agent_id' => (string) $model->cursor_agent_id,
            'status' => (string) $model->status,
            'prompt_version' => (int) ($model->prompt_version ?? 1),
        ];

        if ($this->needsPromptRefresh($record)) {
            return null;
        }

        return [
            'id' => $record['id'],
            'cursor_agent_id' => $record['cursor_agent_id'],
            'status' => $record['status'],
        ];
    }

    public function findRecordForUser(int $userId): ?array
    {
        $model = WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->first();

        if ($model === null) {
            return null;
        }

        return [
            'id' => (int) $model->id,
            'cursor_agent_id' => $model->cursor_agent_id !== null ? (string) $model->cursor_agent_id : null,
            'status' => (string) $model->status,
            'prompt_version' => (int) ($model->prompt_version ?? 1),
            'error_message' => $model->error_message !== null ? (string) $model->error_message : null,
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];
    }

    public function needsPromptRefresh(array $record): bool
    {
        $current = max(1, (int) config('word_chat.prompt_version', 1));
        $stored = (int) ($record['prompt_version'] ?? 1);

        return $stored < $current;
    }

    public function saveAgent(int $userId, string $cursorAgentId, string $status = 'active'): array
    {
        $model = WordChatAgentModel::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'cursor_agent_id' => $cursorAgentId,
                'status' => $status,
                'error_message' => null,
            ],
        );

        return [
            'id' => (int) $model->id,
            'cursor_agent_id' => (string) $model->cursor_agent_id,
            'status' => (string) $model->status,
        ];
    }

    public function markCreating(int $userId): void
    {
        WordChatAgentModel::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'cursor_agent_id' => null,
                'status' => 'creating',
                'error_message' => null,
            ],
        );
    }

    public function markReady(int $userId, string $cursorAgentId): void
    {
        WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->update([
                'cursor_agent_id' => $cursorAgentId,
                'status' => 'active',
                'prompt_version' => max(1, (int) config('word_chat.prompt_version', 1)),
                'error_message' => null,
            ]);
    }

    public function markError(int $userId, string $message): void
    {
        WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->update([
                'status' => 'error',
                'error_message' => $message,
            ]);
    }

    public function markLastRun(int $userId): void
    {
        WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->update(['last_run_at' => now()]);
    }

    public function archiveForUser(int $userId): void
    {
        WordChatAgentModel::query()
            ->where('user_id', $userId)
            ->update([
                'status' => 'archived',
                'error_message' => null,
            ]);
    }
}
