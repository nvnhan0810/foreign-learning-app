<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListeningAssessment;
use App\Models\MediaItem;
use Flc\Listening\Application\Command\StartListeningSession;
use Flc\Listening\Application\Command\SubmitListeningAttempt;
use Flc\Listening\Application\Query\GetListeningAssessmentQuestions;
use Flc\Listening\Application\Query\GetListeningAttempts;
use Flc\Listening\Application\Query\GetListeningSessionOptions;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ListeningAssessmentController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function show(Request $request, ListeningAssessment $listeningAssessment): JsonResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        $listeningAssessment->load(['mediaItem:id,title,url,type,source_id,audio_path,analysis_status,language']);

        return response()->json(['data' => $listeningAssessment]);
    }

    public function questions(Request $request, ListeningAssessment $listeningAssessment): JsonResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        try {
            $payload = $this->queries->ask(new GetListeningAssessmentQuestions(
                $listeningAssessment->id,
                $request->user()->id,
            ));
        } catch (AccessDeniedHttpException) {
            abort(403);
        }

        if (! ($payload['ready'] ?? false)) {
            return response()->json([
                'message' => 'Assessment is not ready yet.',
                'data' => ['status' => $payload['status'] ?? null],
            ], 422);
        }

        unset($payload['ready']);

        return response()->json(['data' => $payload]);
    }

    public function submitAttempt(Request $request, ListeningAssessment $listeningAssessment): JsonResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'exists:listening_questions,id'],
            'answers.*.answer' => ['required', 'string'],
            'started_at' => ['nullable', 'date'],
        ]);

        try {
            $result = $this->commands->dispatch(new SubmitListeningAttempt(
                assessmentId: $listeningAssessment->id,
                userId: $request->user()->id,
                answers: array_map(static fn (array $answer) => [
                    'question_id' => (int) $answer['question_id'],
                    'answer' => $answer['answer'],
                ], $data['answers']),
                startedAt: $data['started_at'] ?? null,
                strict: true,
            ));
        } catch (AccessDeniedHttpException) {
            abort(403);
        } catch (UnprocessableEntityHttpException $e) {
            abort(422, $e->getMessage());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function attempts(Request $request, ListeningAssessment $listeningAssessment): JsonResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        $attempts = $this->queries->ask(new GetListeningAttempts(
            $listeningAssessment->id,
            $request->user()->id,
        ));

        return response()->json(['data' => $attempts]);
    }

    public function startSession(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        $data = $request->validate([
            'type' => ['required', 'in:quiz,test,exam'],
        ]);

        try {
            $session = $this->commands->dispatch(new StartListeningSession(
                $mediaItem->id,
                $request->user()->id,
                $data['type'],
            ));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $session], 201);
    }

    public function sessionOptions(Request $request, MediaItem $mediaItem): JsonResponse
    {
        $this->authorizeMedia($request, $mediaItem);

        return response()->json([
            'data' => $this->queries->ask(new GetListeningSessionOptions($mediaItem->id)),
        ]);
    }

    private function authorizeAssessment(Request $request, ListeningAssessment $assessment): void
    {
        if ($assessment->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function authorizeMedia(Request $request, MediaItem $mediaItem): void
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
