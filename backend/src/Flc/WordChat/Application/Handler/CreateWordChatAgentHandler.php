<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\WordChat\Application\Command\CreateWordChatAgent;
use Flc\WordChat\Application\CursorWordChatGateway;
use Flc\WordChat\Application\WordChatAgentRepository;
use Flc\WordChat\Application\WordChatPromptBuilder;

final class CreateWordChatAgentHandler implements CommandHandler
{
    public function __construct(
        private readonly CursorWordChatGateway $cursor,
        private readonly WordChatAgentRepository $agents,
        private readonly WordChatPromptBuilder $prompts,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof CreateWordChatAgent);

        if (! $this->cursor->isConfigured()) {
            $this->agents->markError($command->userId, 'Word chat is not configured.');

            return ['created' => false];
        }

        $record = $this->agents->findRecordForUser($command->userId);

        if ($record !== null && $record['status'] === 'active' && $record['cursor_agent_id'] !== null) {
            if (! $this->agents->needsPromptRefresh($record)) {
                return ['created' => true, 'status' => 'ready'];
            }

            $this->cursor->archiveAgent($record['cursor_agent_id']);
            $this->agents->archiveForUser($command->userId);
            $this->agents->markCreating($command->userId);
            $record = $this->agents->findRecordForUser($command->userId);
        }

        if ($record === null || $record['status'] !== 'creating') {
            return ['created' => false, 'status' => 'skipped'];
        }

        $cursorRun = $this->cursor->createAgent($this->prompts->buildWarmupPrompt());

        if ($cursorRun === null) {
            $this->agents->markError(
                $command->userId,
                'Word chat timed out while starting. Please reload the page.',
            );

            return ['created' => false, 'status' => 'error'];
        }

        $warmupWait = max(10, (int) config('word_chat.cursor_warmup_run_wait_seconds', 180));
        if (! $this->cursor->waitForRunSettlement($cursorRun['agentId'], $cursorRun['runId'], $warmupWait)) {
            $this->agents->markError(
                $command->userId,
                'Word chat warmup timed out. Please reload the page.',
            );

            return ['created' => false, 'status' => 'error'];
        }

        $this->agents->markReady($command->userId, $cursorRun['agentId']);

        return [
            'created' => true,
            'status' => 'ready',
            'cursor_agent_id' => $cursorRun['agentId'],
        ];
    }
}
