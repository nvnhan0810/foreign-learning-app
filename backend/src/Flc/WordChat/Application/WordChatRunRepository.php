<?php

namespace Flc\WordChat\Application;

use Flc\WordChat\Domain\WordChatRun;

interface WordChatRunRepository
{
    public function save(WordChatRun $run): WordChatRun;

    public function findByCursorRunForUser(int $userId, string $cursorRunId): ?WordChatRun;

    public function complete(
        int $userId,
        string $cursorRunId,
        string $assistantContent,
        int $assistantMessageId,
    ): void;

    public function markError(int $userId, string $cursorRunId): void;
}
