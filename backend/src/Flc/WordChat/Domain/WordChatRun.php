<?php

namespace Flc\WordChat\Domain;

final class WordChatRun
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public int $wordChatAgentId,
        public string $cursorAgentId,
        public string $cursorRunId,
        public int $userMessageId,
        public ?int $assistantMessageId,
        public string $status,
        public ?string $assistantContent = null,
    ) {}
}
