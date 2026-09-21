<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Flc\WordChat\Application\Command\EnsureWordChatAgent;
use Flc\WordChat\Application\Command\ResetWordChatAgent;
use Flc\WordChat\Application\Command\SendWordChatMessage;
use Flc\WordChat\Application\Query\GetWordChatAgentStatus;
use Flc\WordChat\Application\Query\ListLearningInsights;
use Flc\WordChat\Application\Query\ListWordChatMessages;
use Flc\WordChat\Application\WordChatRunRepository;
use Flc\WordChat\Application\WordChatStreamProxy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WordChatController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly WordChatRunRepository $runs,
        private readonly WordChatStreamProxy $streamProxy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $this->queries->ask(new ListWordChatMessages(
            userId: (int) $request->user()->id,
            beforeId: isset($data['before']) ? (int) $data['before'] : null,
            limit: (int) ($data['limit'] ?? config('word_chat.history_page_size', 50)),
        ));

        return response()->json(['data' => $items]);
    }

    public function agentStatus(Request $request): JsonResponse
    {
        $status = $this->queries->ask(new GetWordChatAgentStatus(
            userId: (int) $request->user()->id,
        ));

        return response()->json(['data' => $status]);
    }

    public function ensureAgent(Request $request): JsonResponse
    {
        $result = $this->commands->dispatch(new EnsureWordChatAgent(
            userId: (int) $request->user()->id,
        ));

        $code = ($result['ready'] ?? false) ? 200 : 202;

        return response()->json(['data' => $result], $code);
    }

    public function insights(Request $request): JsonResponse
    {
        $data = $request->validate([
            'word' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $this->queries->ask(new ListLearningInsights(
            userId: (int) $request->user()->id,
            word: isset($data['word']) ? (string) $data['word'] : null,
            limit: (int) ($data['limit'] ?? 50),
        ));

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
        ]);

        $result = $this->commands->dispatch(new SendWordChatMessage(
            userId: (int) $request->user()->id,
            text: $data['text'],
        ));

        return response()->json(['data' => $result], 202);
    }

    public function stream(Request $request, string $runId): StreamedResponse
    {
        $run = $this->runs->findByCursorRunForUser((int) $request->user()->id, $runId);

        if ($run === null) {
            abort(404);
        }

        if ($run->status === 'finished' && $run->assistantContent !== null) {
            return response()->stream(function () use ($run): void {
                echo "event: result\n";
                echo 'data: '.json_encode([
                    'runId' => $run->cursorRunId,
                    'status' => 'FINISHED',
                    'text' => $run->assistantContent,
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                echo "event: done\n";
                echo "data: {}\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }, 200, $this->streamHeaders());
        }

        $lastEventId = $request->header('Last-Event-ID');

        return response()->stream(function () use ($run, $lastEventId): void {
            $this->streamProxy->pipe($run, is_string($lastEventId) ? $lastEventId : null, function (string $chunk): void {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });
        }, 200, $this->streamHeaders());
    }

    public function reset(Request $request): JsonResponse
    {
        $result = $this->commands->dispatch(new ResetWordChatAgent(
            userId: (int) $request->user()->id,
        ));

        return response()->json(['data' => $result]);
    }

    /** @return array<string, string> */
    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
