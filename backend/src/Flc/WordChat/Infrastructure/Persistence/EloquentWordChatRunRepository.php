<?php

namespace Flc\WordChat\Infrastructure\Persistence;

use App\Models\WordChatRun as WordChatRunModel;
use Flc\WordChat\Application\WordChatRunRepository;
use Flc\WordChat\Domain\WordChatRun;
use Illuminate\Support\Facades\DB;

final class EloquentWordChatRunRepository implements WordChatRunRepository
{
    public function save(WordChatRun $run): WordChatRun
    {
        $model = WordChatRunModel::query()->create([
            'user_id' => $run->userId,
            'word_chat_agent_id' => $run->wordChatAgentId,
            'cursor_agent_id' => $run->cursorAgentId,
            'cursor_run_id' => $run->cursorRunId,
            'user_message_id' => $run->userMessageId,
            'assistant_message_id' => $run->assistantMessageId,
            'status' => $run->status,
            'assistant_content' => $run->assistantContent,
        ]);

        return new WordChatRun(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            wordChatAgentId: (int) $model->word_chat_agent_id,
            cursorAgentId: (string) $model->cursor_agent_id,
            cursorRunId: (string) $model->cursor_run_id,
            userMessageId: (int) $model->user_message_id,
            assistantMessageId: $model->assistant_message_id !== null ? (int) $model->assistant_message_id : null,
            status: (string) $model->status,
            assistantContent: $model->assistant_content,
        );
    }

    public function findByCursorRunForUser(int $userId, string $cursorRunId): ?WordChatRun
    {
        $model = WordChatRunModel::query()
            ->where('user_id', $userId)
            ->where('cursor_run_id', $cursorRunId)
            ->first();

        if ($model === null) {
            return null;
        }

        return new WordChatRun(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            wordChatAgentId: (int) $model->word_chat_agent_id,
            cursorAgentId: (string) $model->cursor_agent_id,
            cursorRunId: (string) $model->cursor_run_id,
            userMessageId: (int) $model->user_message_id,
            assistantMessageId: $model->assistant_message_id !== null ? (int) $model->assistant_message_id : null,
            status: (string) $model->status,
            assistantContent: $model->assistant_content,
        );
    }

    public function complete(
        int $userId,
        string $cursorRunId,
        string $assistantContent,
        int $assistantMessageId,
    ): void {
        DB::transaction(function () use ($userId, $cursorRunId, $assistantContent, $assistantMessageId): void {
            $run = WordChatRunModel::query()
                ->where('user_id', $userId)
                ->where('cursor_run_id', $cursorRunId)
                ->lockForUpdate()
                ->first();

            if ($run === null || $run->status === 'finished') {
                return;
            }

            $run->update([
                'status' => 'finished',
                'assistant_content' => $assistantContent,
                'assistant_message_id' => $assistantMessageId,
            ]);
        });
    }

    public function markError(int $userId, string $cursorRunId): void
    {
        WordChatRunModel::query()
            ->where('user_id', $userId)
            ->where('cursor_run_id', $cursorRunId)
            ->update(['status' => 'error']);
    }
}
