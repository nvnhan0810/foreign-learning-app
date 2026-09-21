<?php

namespace Flc\Media\Infrastructure\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CursorAgentService
{
    public function isConfigured(): bool
    {
        return (bool) config('listening.cursor_api_key');
    }

    public function completeJson(string $prompt): ?array
    {
        $jsonPrompt = $prompt."\n\nRespond with valid JSON only. Do not wrap the JSON in markdown code fences.";

        $result = $this->complete($jsonPrompt);

        if ($result === null) {
            return null;
        }

        return $this->parseJson($result);
    }

    public function complete(string $prompt): ?string
    {
        $apiKey = config('listening.cursor_api_key');

        if (! $apiKey) {
            return null;
        }

        $baseUrl = rtrim((string) config('listening.cursor_api_base'), '/');

        try {
            $createResponse = $this->client($apiKey)->post("{$baseUrl}/v1/agents", [
                'prompt' => [
                    'text' => $prompt,
                ],
                'model' => [
                    'id' => config('listening.cursor_model'),
                ],
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Cursor agent create timed out / unreachable; falling back', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::warning('Cursor agent create failed; falling back', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $createResponse->successful()) {
            Log::warning('Cursor agent create failed', [
                'status' => $createResponse->status(),
                'body' => $createResponse->body(),
            ]);

            return null;
        }

        $agentId = $createResponse->json('agent.id');
        $runId = $createResponse->json('run.id');

        if (! is_string($agentId) || ! is_string($runId)) {
            return null;
        }

        try {
            return $this->waitForRunResult($baseUrl, $apiKey, $agentId, $runId);
        } finally {
            $this->archiveAgent($baseUrl, $apiKey, $agentId);
        }
    }

    private function waitForRunResult(string $baseUrl, string $apiKey, string $agentId, string $runId): ?string
    {
        $deadline = time() + (int) config('listening.cursor_timeout_seconds');
        $interval = max(1, (int) config('listening.cursor_poll_interval_seconds'));

        while (time() < $deadline) {
            try {
                $response = $this->client($apiKey)->get("{$baseUrl}/v1/agents/{$agentId}/runs/{$runId}");
            } catch (ConnectionException $e) {
                Log::warning('Cursor run poll timed out / unreachable; falling back', [
                    'agent_id' => $agentId,
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            } catch (Throwable $e) {
                Log::warning('Cursor run poll failed; falling back', [
                    'agent_id' => $agentId,
                    'run_id' => $runId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                Log::warning('Cursor run poll failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $status = $response->json('status');

            if ($status === 'FINISHED') {
                $result = $response->json('result');

                return is_string($result) ? $result : null;
            }

            if (in_array($status, ['ERROR', 'CANCELLED', 'EXPIRED'], true)) {
                Log::warning('Cursor run ended without result', [
                    'agent_id' => $agentId,
                    'run_id' => $runId,
                    'status' => $status,
                ]);

                return null;
            }

            sleep($interval);
        }

        Log::warning('Cursor run timed out', [
            'agent_id' => $agentId,
            'run_id' => $runId,
        ]);

        return null;
    }

    private function archiveAgent(string $baseUrl, string $apiKey, string $agentId): void
    {
        try {
            $this->client($apiKey, archive: true)
                ->post("{$baseUrl}/v1/agents/{$agentId}/archive");
        } catch (Throwable $e) {
            Log::debug('Cursor agent archive skipped', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function client(string $apiKey, bool $archive = false): PendingRequest
    {
        $timeout = $archive
            ? 10
            : max(5, (int) config('listening.cursor_http_timeout_seconds', 60));
        $connectTimeout = max(3, (int) config('listening.cursor_connect_timeout_seconds', 10));
        $retries = max(0, (int) config('listening.cursor_http_retries', 1));

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
     * @return array<string, mixed>|null
     */
    private function parseJson(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}
