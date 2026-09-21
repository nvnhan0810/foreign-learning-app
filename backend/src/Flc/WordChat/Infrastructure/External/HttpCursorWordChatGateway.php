<?php

namespace Flc\WordChat\Infrastructure\External;

use Flc\WordChat\Application\CursorWordChatGateway as CursorWordChatGatewayContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HttpCursorWordChatGateway implements CursorWordChatGatewayContract
{
    public function isConfigured(): bool
    {
        return (bool) config('listening.cursor_api_key');
    }

    public function createAgent(string $prompt): ?array
    {
        $apiKey = config('listening.cursor_api_key');
        if (! $apiKey) {
            return null;
        }

        $baseUrl = $this->baseUrl();

        try {
            $response = $this->jsonClient($apiKey, $this->createTimeout())->post("{$baseUrl}/v1/agents", [
                'prompt' => ['text' => $prompt],
                'name' => 'FLC Word Chat',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Word chat cursor agent create timed out', ['error' => $e->getMessage()]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Word chat cursor agent create failed', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->parseAgentRunIds($response);
    }

    public function followUp(string $agentId, string $prompt): ?array
    {
        $apiKey = config('listening.cursor_api_key');
        if (! $apiKey) {
            return null;
        }

        $baseUrl = $this->baseUrl();

        try {
            $response = $this->jsonClient($apiKey)->post("{$baseUrl}/v1/agents/{$agentId}/runs", [
                'prompt' => ['text' => $prompt],
            ]);
        } catch (Throwable $e) {
            Log::warning('Word chat cursor follow-up failed', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Word chat cursor follow-up rejected', [
                'agent_id' => $agentId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $runId = $response->json('run.id');

        if (! is_string($runId) || $runId === '') {
            return null;
        }

        return ['agentId' => $agentId, 'runId' => $runId];
    }

    public function openRunStream(string $agentId, string $runId, ?string $lastEventId = null): ?Response
    {
        $apiKey = config('listening.cursor_api_key');
        if (! $apiKey) {
            return null;
        }

        $waitSeconds = max(1, (int) config('word_chat.cursor_stream_wait_seconds', 30));
        $this->waitForRunStreamReady($agentId, $runId, $waitSeconds);

        $attempts = max(1, (int) config('word_chat.cursor_stream_open_attempts', 20));
        $delayMs = max(100, (int) config('word_chat.cursor_stream_open_delay_ms', 1000));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->tryOpenRunStream($apiKey, $agentId, $runId, $lastEventId);
            if ($response !== null) {
                return $response;
            }

            if ($attempt < $attempts) {
                usleep($delayMs * 1000);
            }
        }

        return null;
    }

    public function waitForRunSettlement(string $agentId, string $runId, int $timeoutSeconds): bool
    {
        $deadline = time() + max(1, $timeoutSeconds);
        $interval = max(1, (int) config('listening.cursor_poll_interval_seconds', 2));

        while (time() < $deadline) {
            $run = $this->getRun($agentId, $runId);
            if ($run === null) {
                sleep($interval);

                continue;
            }

            if ($this->isTerminalRunStatus($run['status'])) {
                return true;
            }

            sleep($interval);
        }

        $run = $this->getRun($agentId, $runId);

        return $run !== null && $this->isTerminalRunStatus($run['status']);
    }

    public function getRun(string $agentId, string $runId): ?array
    {
        $apiKey = config('listening.cursor_api_key');
        if (! $apiKey) {
            return null;
        }

        $baseUrl = $this->baseUrl();

        try {
            $response = $this->jsonClient($apiKey)->get("{$baseUrl}/v1/agents/{$agentId}/runs/{$runId}");
        } catch (Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $status = $response->json('status');
        $result = $response->json('result');

        return [
            'status' => is_string($status) ? $status : 'UNKNOWN',
            'text' => is_string($result) ? $result : null,
        ];
    }

    public function archiveAgent(string $agentId): void
    {
        $apiKey = config('listening.cursor_api_key');
        if (! $apiKey) {
            return;
        }

        $baseUrl = $this->baseUrl();

        try {
            Http::withBasicAuth($apiKey, '')
                ->acceptJson()
                ->timeout(10)
                ->post("{$baseUrl}/v1/agents/{$agentId}/archive");
        } catch (Throwable $e) {
            Log::debug('Word chat cursor archive skipped', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('listening.cursor_api_base'), '/');
    }

    private function jsonClient(string $apiKey, ?int $timeout = null): PendingRequest
    {
        return $this->baseClient(
            $apiKey,
            $timeout ?? (int) config('word_chat.cursor_http_timeout_seconds', 90),
            $timeout === null,
        );
    }

    private function createTimeout(): int
    {
        return max(30, (int) config('word_chat.cursor_create_timeout_seconds', 180));
    }

    private function streamClient(string $apiKey): PendingRequest
    {
        $timeout = max(30, (int) config('word_chat.cursor_stream_timeout_seconds', 300));
        $connectTimeout = max(3, (int) config('word_chat.cursor_connect_timeout_seconds', 10));

        return Http::withBasicAuth($apiKey, '')
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);
    }

    private function waitForRunStreamReady(string $agentId, string $runId, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;
        $interval = max(1, (int) config('listening.cursor_poll_interval_seconds', 2));

        while (time() < $deadline) {
            $run = $this->getRun($agentId, $runId);
            if ($run !== null && $this->isStreamReadyStatus($run['status'])) {
                return;
            }

            usleep($interval * 1_000_000);
        }
    }

    private function tryOpenRunStream(string $apiKey, string $agentId, string $runId, ?string $lastEventId): ?Response
    {
        $baseUrl = $this->baseUrl();
        $headers = [];

        if ($lastEventId !== null && $lastEventId !== '') {
            $headers['Last-Event-ID'] = $lastEventId;
        }

        try {
            $request = $this->streamClient($apiKey)
                ->withOptions(['stream' => true]);

            if ($headers !== []) {
                $request = $request->withHeaders($headers);
            }

            $response = $request->get("{$baseUrl}/v1/agents/{$agentId}/runs/{$runId}/stream");
        } catch (Throwable $e) {
            Log::warning('Word chat cursor stream open failed', [
                'agent_id' => $agentId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->successful()) {
            return $response;
        }

        Log::warning('Word chat cursor stream open rejected', [
            'agent_id' => $agentId,
            'run_id' => $runId,
            'status' => $response->status(),
            'body' => substr($response->body(), 0, 500),
        ]);

        return null;
    }

    private function isStreamReadyStatus(string $status): bool
    {
        return in_array(strtoupper($status), ['RUNNING', 'FINISHED'], true);
    }

    private function isTerminalRunStatus(string $status): bool
    {
        return in_array(strtoupper($status), ['FINISHED', 'FAILED', 'CANCELLED', 'ERROR'], true);
    }

    private function baseClient(string $apiKey, int $timeout, bool $retry = true): PendingRequest
    {
        $connectTimeout = max(3, (int) config('word_chat.cursor_connect_timeout_seconds', 10));
        $retries = $retry ? max(0, (int) config('word_chat.cursor_http_retries', 1)) : 0;

        $request = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);

        if ($retries > 0) {
            $request = $request->retry(
                $retries,
                1000,
                fn (Throwable $exception) => $exception instanceof ConnectionException,
                throw: false,
            );
        }

        return $request;
    }

    /**
     * @return array{agentId: string, runId: string}|null
     */
    private function parseAgentRunIds(Response $response): ?array
    {
        if (! $response->successful()) {
            Log::warning('Word chat cursor agent create rejected', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $agentId = $response->json('agent.id');
        $runId = $response->json('run.id');

        if (! is_string($agentId) || ! is_string($runId) || $agentId === '' || $runId === '') {
            return null;
        }

        return ['agentId' => $agentId, 'runId' => $runId];
    }
}
