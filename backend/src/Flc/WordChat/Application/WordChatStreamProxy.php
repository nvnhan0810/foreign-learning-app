<?php

namespace Flc\WordChat\Application;

use Flc\Shared\Application\CommandBus;
use Flc\WordChat\Application\Command\CompleteWordChatRun;
use Flc\WordChat\Domain\WordChatRun;
use Illuminate\Http\Client\Response;
use Throwable;

final class WordChatStreamProxy
{
    public function __construct(
        private readonly CursorWordChatGateway $cursor,
        private readonly WordChatRunRepository $runs,
        private readonly WordChatMessageRepository $messages,
        private readonly CommandBus $commands,
    ) {}

    /**
     * Proxy Cursor SSE to output stream and persist assistant reply when finished.
     */
    public function pipe(WordChatRun $run, ?string $lastEventId, callable $write): void
    {
        try {
            $this->pipeStream($run, $lastEventId, $write);
        } catch (Throwable $exception) {
            report($exception);
            $this->runs->markError($run->userId, $run->cursorRunId);
            $this->emitClientEvent($write, 'error', [
                'code' => 'stream_failed',
                'message' => 'Word chat stream failed.',
            ]);
        }
    }

    private function pipeStream(WordChatRun $run, ?string $lastEventId, callable $write): void
    {
        $this->emitLookupForRun($run, $write);

        $response = $this->cursor->openRunStream($run->cursorAgentId, $run->cursorRunId, $lastEventId);

        if ($response === null) {
            if ($this->emitTerminalRunFallback($run, $write)) {
                return;
            }

            $this->emitClientEvent($write, 'error', [
                'code' => 'stream_unavailable',
                'message' => 'Could not open word chat stream.',
            ]);
            $this->runs->markError($run->userId, $run->cursorRunId);

            return;
        }

        $assistantText = '';
        $this->relayStream($response, $write, $assistantText);

        if ($assistantText === '') {
            $terminal = $this->cursor->getRun($run->cursorAgentId, $run->cursorRunId);
            $assistantText = trim((string) ($terminal['text'] ?? ''));
        }

        if ($assistantText !== '') {
            $saved = $this->persistAssistantReply($run, $assistantText, $write);
            if ($saved === null) {
                return;
            }
        } else {
            $this->runs->markError($run->userId, $run->cursorRunId);
            $this->emitClientEvent($write, 'error', [
                'code' => 'empty_reply',
                'message' => 'Word chat returned an empty reply.',
            ]);
        }
    }

    private function emitTerminalRunFallback(WordChatRun $run, callable $write): bool
    {
        $terminal = $this->cursor->getRun($run->cursorAgentId, $run->cursorRunId);
        if ($terminal === null) {
            return false;
        }

        $text = trim((string) ($terminal['text'] ?? ''));
        if ($text === '' || strtoupper($terminal['status']) !== 'FINISHED') {
            return false;
        }

        $this->emitLookupForRun($run, $write);

        $this->emitClientEvent($write, 'result', [
            'runId' => $run->cursorRunId,
            'status' => 'FINISHED',
            'text' => $text,
        ]);

        $saved = $this->persistAssistantReply($run, $text, $write);
        if ($saved === null) {
            return false;
        }

        $this->emitClientEvent($write, 'done', []);

        return true;
    }

    /**
     * @return array<string, mixed>|null Saved assistant payload, or null after emitting SSE error.
     */
    private function persistAssistantReply(WordChatRun $run, string $assistantText, callable $write): ?array
    {
        try {
            $saved = $this->commands->dispatch(new CompleteWordChatRun(
                userId: $run->userId,
                cursorRunId: $run->cursorRunId,
                assistantContent: $assistantText,
            ));

            if (! is_array($saved)) {
                $this->runs->markError($run->userId, $run->cursorRunId);
                $this->emitClientEvent($write, 'error', [
                    'code' => 'empty_reply',
                    'message' => 'Word chat returned an empty reply.',
                ]);

                return null;
            }

            $this->emitSavedEvent($write, $saved);

            return $saved;
        } catch (Throwable $exception) {
            report($exception);
            $this->runs->markError($run->userId, $run->cursorRunId);
            $this->emitClientEvent($write, 'error', [
                'code' => 'persist_failed',
                'message' => 'Could not save word chat reply.',
            ]);

            return null;
        }
    }

    private function relayStream(Response $response, callable $write, string &$assistantText): void
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->accumulateAssistantText($block, $assistantText);
            }

            $write($chunk);
        }

        if ($buffer !== '') {
            $this->accumulateAssistantText($buffer, $assistantText);
            $write($buffer);
        }
    }

    private function accumulateAssistantText(string $block, string &$assistantText): void
    {
        $event = null;
        $data = null;

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = trim(substr($line, 5));
            }
        }

        if ($data === null || $data === '') {
            return;
        }

        $payload = json_decode($data, true);
        if (! is_array($payload)) {
            return;
        }

        if ($event === 'assistant' && isset($payload['text']) && is_string($payload['text'])) {
            $assistantText .= $payload['text'];
        }

        if ($event === 'result' && isset($payload['text']) && is_string($payload['text']) && trim($payload['text']) !== '') {
            $assistantText = (string) $payload['text'];
        }
    }

    /**
     * @param  array<string, mixed>  $saved
     */
    private function emitSavedEvent(callable $write, array $saved): void
    {
        $lookup = is_array($saved['metadata']['lookup'] ?? null) ? $saved['metadata']['lookup'] : null;
        if ($lookup !== null) {
            $this->emitClientEvent($write, 'lookup', [
                'lookup' => $lookup,
            ]);
        }

        $insights = is_array($saved['insights'] ?? null) ? $saved['insights'] : [];
        if ($insights !== []) {
            $this->emitClientEvent($write, 'insights', [
                'items' => $insights,
            ]);
        }

        if (is_array($saved['saved_vocabulary'] ?? null)) {
            $this->emitClientEvent($write, 'vocab_saved', [
                'vocabulary' => $saved['saved_vocabulary'],
            ]);
        }

        $this->emitClientEvent($write, 'saved', [
            'assistant_message' => $saved,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitClientEvent(callable $write, string $event, array $payload): void
    {
        $write("event: {$event}\n");
        $write('data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n");
    }

    private function emitLookupForRun(WordChatRun $run, callable $write): void
    {
        $userMessage = $this->messages->findById($run->userId, $run->userMessageId);
        $lookup = is_array($userMessage?->metadata['lookup'] ?? null)
            ? $userMessage->metadata['lookup']
            : null;

        if ($lookup === null) {
            return;
        }

        $this->emitClientEvent($write, 'lookup', [
            'lookup' => $lookup,
        ]);
    }
}
