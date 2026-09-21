<?php

namespace Flc\WordChat\Application\Query;

use Flc\Shared\Application\Query;

final class GetWordChatAgentStatus implements Query
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
