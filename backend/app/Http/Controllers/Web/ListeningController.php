<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ListeningAssessment;
use App\Models\MediaItem;
use Flc\Listening\Application\Command\InitializeSessionQuestions;
use Flc\Listening\Application\Command\ResumeOrStartListeningSession;
use Flc\Listening\Application\Command\SubmitListeningAttempt;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Shared\Application\CommandBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ListeningController extends Controller
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly ListeningAssessmentRepository $assessments,
    ) {}

    public function start(Request $request, MediaItem $mediaItem): RedirectResponse
    {
        if ($mediaItem->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'type' => ['required', 'in:quiz,test,exam'],
        ]);

        try {
            $session = $this->commands->dispatch(new ResumeOrStartListeningSession(
                $mediaItem->id,
                $request->user()->id,
                $data['type'],
            ));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('user.listening.show', $session['assessment_id']);
    }

    public function show(Request $request, ListeningAssessment $listeningAssessment): Response|RedirectResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        if ($listeningAssessment->status !== ListeningAssessment::STATUS_READY) {
            return redirect()->back()->with('error', 'Assessment is not ready yet.');
        }

        $listeningAssessment->load('mediaItem');
        $result = session('listening_result');
        $sessionKey = $this->sessionCacheKey($listeningAssessment);
        $userId = $request->user()->id;
        $questions = collect();

        if ($result) {
            session()->forget($sessionKey);
        } elseif ($this->assessments->hasCompletedAttempt($listeningAssessment->id, $userId)) {
            session()->forget($sessionKey);

            $lastAttempt = $this->assessments->latestAttemptForUser($listeningAssessment->id, $userId);

            return Inertia::render('Listening/Show', [
                'assessment' => [
                    'id' => $listeningAssessment->id,
                    'title' => $listeningAssessment->title,
                    'type' => $listeningAssessment->type,
                    'status' => $listeningAssessment->status,
                ],
                'mediaItem' => $listeningAssessment->mediaItem
                    ? [
                        'id' => $listeningAssessment->mediaItem->id,
                        'title' => $listeningAssessment->mediaItem->title,
                        'type' => $listeningAssessment->mediaItem->type,
                        'source_id' => $listeningAssessment->mediaItem->source_id,
                        'url' => $listeningAssessment->mediaItem->url,
                        'has_audio' => filled($listeningAssessment->mediaItem->audio_path),
                    ]
                    : null,
                'questions' => [],
                'result' => [
                    'score' => $lastAttempt?->score,
                    'total' => $lastAttempt?->total,
                    'percentage' => $lastAttempt?->percentage,
                ],
            ]);
        } elseif (! session()->has($sessionKey)) {
            $questionIds = array_map('intval', $listeningAssessment->question_ids ?? []);

            try {
                if ($questionIds === []) {
                    $questionIds = $this->commands->dispatch(new InitializeSessionQuestions(
                        $listeningAssessment->id,
                        $userId,
                    ));
                } else {
                    shuffle($questionIds);
                }

                session([$sessionKey => $questionIds]);
            } catch (RuntimeException $e) {
                return redirect()
                    ->route('user.home.media.show', $listeningAssessment->mediaItem)
                    ->with('error', $e->getMessage());
            }
        } else {
            $listeningAssessment->question_ids = session($sessionKey);
        }

        if (! $result) {
            $questions = collect($listeningAssessment->sessionQuestions())->map(function ($question) {
                return [
                    'id' => $question->id ?? $question['id'] ?? null,
                    'prompt' => $question->prompt ?? $question->question ?? $question['prompt'] ?? $question['question'] ?? '',
                    'options' => $question->options ?? $question['options'] ?? [],
                ];
            })->values();
        }

        return Inertia::render('Listening/Show', [
            'assessment' => [
                'id' => $listeningAssessment->id,
                'title' => $listeningAssessment->title,
                'type' => $listeningAssessment->type,
                'status' => $listeningAssessment->status,
            ],
            'mediaItem' => $listeningAssessment->mediaItem
                ? [
                    'id' => $listeningAssessment->mediaItem->id,
                    'title' => $listeningAssessment->mediaItem->title,
                    'type' => $listeningAssessment->mediaItem->type,
                    'source_id' => $listeningAssessment->mediaItem->source_id,
                    'url' => $listeningAssessment->mediaItem->url,
                    'has_audio' => filled($listeningAssessment->mediaItem->audio_path),
                ]
                : null,
            'questions' => $questions,
            'result' => $result,
        ]);
    }

    public function submit(Request $request, ListeningAssessment $listeningAssessment): RedirectResponse
    {
        $this->authorizeAssessment($request, $listeningAssessment);

        if ($listeningAssessment->status !== ListeningAssessment::STATUS_READY) {
            return redirect()->back()->with('error', 'Assessment is not ready yet.');
        }

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*' => ['required', 'string'],
        ]);

        $answers = [];

        foreach ($data['answers'] as $questionId => $answer) {
            $answers[] = [
                'question_id' => (int) $questionId,
                'answer' => $answer,
            ];
        }

        try {
            $result = $this->commands->dispatch(new SubmitListeningAttempt(
                assessmentId: $listeningAssessment->id,
                userId: $request->user()->id,
                answers: $answers,
                strict: false,
            ));
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        session()->forget($this->sessionCacheKey($listeningAssessment));

        return redirect()->route('user.listening.show', $listeningAssessment)
            ->with('listening_result', [
                'score' => $result['score'],
                'total' => $result['total'],
                'percentage' => $result['percentage'],
            ])
            ->with('success', 'Submitted.');
    }

    private function authorizeAssessment(Request $request, ListeningAssessment $listeningAssessment): void
    {
        if ($listeningAssessment->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function sessionCacheKey(ListeningAssessment $listeningAssessment): string
    {
        return "listening.question_ids.{$listeningAssessment->id}";
    }
}
