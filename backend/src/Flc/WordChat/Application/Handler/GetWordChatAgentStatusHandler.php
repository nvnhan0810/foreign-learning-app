<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\WordChat\Application\Query\GetWordChatAgentStatus;
use Flc\WordChat\Application\WordChatAgentRepository;

final class GetWordChatAgentStatusHandler implements QueryHandler
{
    public function __construct(
        private readonly WordChatAgentRepository $agents,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetWordChatAgentStatus);

        $record = $this->agents->findRecordForUser($query->userId);

        if ($record === null || $record['status'] === 'archived') {
            return [
                'status' => 'missing',
                'ready' => false,
            ];
        }

        if ($record['status'] === 'active' && $record['cursor_agent_id'] !== null) {
            if ($this->agents->needsPromptRefresh($record)) {
                return [
                    'status' => 'missing',
                    'ready' => false,
                ];
            }

            return [
                'status' => 'ready',
                'ready' => true,
            ];
        }

        if ($record['status'] === 'creating') {
            return [
                'status' => 'creating',
                'ready' => false,
            ];
        }

        if ($record['status'] === 'error') {
            return [
                'status' => 'error',
                'ready' => false,
                'error' => $record['error_message'] ?? 'Word chat agent could not be prepared.',
            ];
        }

        return [
            'status' => 'missing',
            'ready' => false,
        ];
    }
}
