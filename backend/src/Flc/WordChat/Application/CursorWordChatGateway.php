<?php

namespace Flc\WordChat\Application;

interface CursorWordChatGateway
{
    public function isConfigured(): bool;

    /**
     * @return array{agentId: string, runId: string}|null
     */
    public function createAgent(string $prompt): ?array;

    /**
     * @return array{agentId: string, runId: string}|null
     */
    public function followUp(string $agentId, string $prompt): ?array;

    /**
     * Open SSE stream for a run. Caller must read and close the body.
     */
    public function openRunStream(string $agentId, string $runId, ?string $lastEventId = null): ?\Illuminate\Http\Client\Response;

    /**
     * @return array{status: string, text: string|null}|null
     */
    public function getRun(string $agentId, string $runId): ?array;

    public function waitForRunSettlement(string $agentId, string $runId, int $timeoutSeconds): bool;

    public function archiveAgent(string $agentId): void;
}
