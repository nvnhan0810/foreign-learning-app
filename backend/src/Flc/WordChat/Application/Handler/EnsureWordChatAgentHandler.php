<?php

namespace Flc\WordChat\Application\Handler;

use App\Jobs\EnsureWordChatAgentJob;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\WordChat\Application\Command\EnsureWordChatAgent;
use Flc\WordChat\Application\CursorWordChatGateway;
use Flc\WordChat\Application\WordChatAgentRepository;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class EnsureWordChatAgentHandler implements CommandHandler
{
    public function __construct(
        private readonly CursorWordChatGateway $cursor,
        private readonly WordChatAgentRepository $agents,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof EnsureWordChatAgent);

        if (! $this->cursor->isConfigured()) {
            throw new ServiceUnavailableHttpException(null, 'Word chat is not configured.');
        }

        $record = $this->agents->findRecordForUser($command->userId);

        if ($record !== null && $record['status'] === 'active' && $record['cursor_agent_id'] !== null) {
            if (! $this->agents->needsPromptRefresh($record)) {
                return [
                    'status' => 'ready',
                    'ready' => true,
                ];
            }

            $this->cursor->archiveAgent($record['cursor_agent_id']);
            $this->agents->archiveForUser($command->userId);
        }

        if ($record !== null && $record['status'] === 'creating' && ! $this->isStale($record)) {
            return [
                'status' => 'creating',
                'ready' => false,
            ];
        }

        $this->agents->markCreating($command->userId);
        EnsureWordChatAgentJob::dispatch($command->userId);

        return [
            'status' => 'creating',
            'ready' => false,
        ];
    }

    /**
     * @param  array{updated_at: string|null}  $record
     */
    private function isStale(array $record): bool
    {
        if ($record['updated_at'] === null) {
            return true;
        }

        $updatedAt = strtotime($record['updated_at']);
        if ($updatedAt === false) {
            return true;
        }

        $staleAfter = max(60, (int) config('word_chat.agent_provision_stale_seconds', 180));

        return (time() - $updatedAt) > $staleAfter;
    }
}
