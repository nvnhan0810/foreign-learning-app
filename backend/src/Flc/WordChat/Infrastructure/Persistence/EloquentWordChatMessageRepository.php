<?php

namespace Flc\WordChat\Infrastructure\Persistence;

use App\Models\WordChatMessage as WordChatMessageModel;
use Flc\WordChat\Application\WordChatMessageRepository;
use Flc\WordChat\Domain\WordChatMessage;

final class EloquentWordChatMessageRepository implements WordChatMessageRepository
{
    public function save(WordChatMessage $message): WordChatMessage
    {
        $model = WordChatMessageModel::query()->create([
            'user_id' => $message->userId,
            'role' => $message->role,
            'content' => $message->content,
            'cursor_run_id' => $message->cursorRunId,
            'metadata' => $message->metadata,
        ]);

        return $this->toDomain($model);
    }

    public function findById(int $userId, int $id): ?WordChatMessage
    {
        $model = WordChatMessageModel::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function updateMetadata(int $userId, int $id, array $metadata): void
    {
        $model = WordChatMessageModel::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->first();

        if ($model === null) {
            return;
        }

        $existing = is_array($model->metadata) ? $model->metadata : [];
        $model->update(['metadata' => array_merge($existing, $metadata)]);
    }

    public function listForUser(int $userId, ?int $beforeId = null, int $limit = 50): array
    {
        $query = WordChatMessageModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)));

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $items = $query->get()->reverse()->values();

        return $items->map(fn (WordChatMessageModel $model) => $this->toDomain($model))->all();
    }

    private function toDomain(WordChatMessageModel $model): WordChatMessage
    {
        return new WordChatMessage(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            role: (string) $model->role,
            content: (string) $model->content,
            cursorRunId: $model->cursor_run_id,
            metadata: is_array($model->metadata) ? $model->metadata : null,
            createdAt: $model->created_at?->toIso8601String(),
        );
    }
}
