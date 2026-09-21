<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\Quiz\Application\Command\RecordQuizAttempt;
use Flc\Quiz\Application\Query\GetNextQuizQuestion;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function next(Request $request): JsonResponse
    {
        $data = $request->validate([
            'insight_id' => ['nullable', 'integer', 'min:1'],
            'vocabulary_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $question = $this->queries->ask(new GetNextQuizQuestion(
            userId: $request->user()->id,
            insightId: isset($data['insight_id']) ? (int) $data['insight_id'] : null,
            vocabularyId: isset($data['vocabulary_id']) ? (int) $data['vocabulary_id'] : null,
        ));

        if (! $question) {
            return response()->json([
                'message' => 'Cần ít nhất 4 từ vựng đã lưu để tạo câu hỏi.',
            ], 422);
        }

        return response()->json(['data' => $question]);
    }

    public function attempt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'exists:vocabularies,id'],
            'question_type' => ['required', 'string', 'max:40'],
            'correct' => ['required', 'boolean'],
            'insight_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->commands->dispatch(new RecordQuizAttempt(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
            questionType: $data['question_type'],
            correct: (bool) $data['correct'],
            insightId: isset($data['insight_id']) ? (int) $data['insight_id'] : null,
        ));

        return response()->json(['data' => $result]);
    }
}
