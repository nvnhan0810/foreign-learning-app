<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\WordChat\Application\Command\ResetWordChatAgent;
use Flc\WordChat\Application\CursorWordChatGateway;
use Flc\WordChat\Application\WordChatAgentRepository;

final class ResetWordChatAgentHandler implements CommandHandler
{
    public function __construct(
        private readonly CursorWordChatGateway $cursor,
        private readonly WordChatAgentRepository $agents,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof ResetWordChatAgent);

        $agent = $this->agents->findForUser($command->userId);

        if ($agent !== null) {
            $this->cursor->archiveAgent($agent['cursor_agent_id']);
            $this->agents->archiveForUser($command->userId);
        }

        return ['reset' => true];
    }
}
