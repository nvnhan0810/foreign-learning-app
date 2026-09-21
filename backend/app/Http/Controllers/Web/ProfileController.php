<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ListeningAttempt;
use App\Models\QuizAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        $vocabularyCount = $user->vocabularies()->count();
        $mediaCount = $user->mediaItems()->count();

        $listeningAvg = ListeningAttempt::query()
            ->where('user_id', $user->id)
            ->avg('percentage');

        $vocabBase = QuizAttempt::query()->where('user_id', $user->id);
        $vocabCorrect = (int) (clone $vocabBase)->where('correct', true)->count();
        $vocabTotal = (int) (clone $vocabBase)->count();
        $vocabPercent = $vocabTotal > 0 ? round(($vocabCorrect / $vocabTotal) * 100, 1) : null;

        $averageScorePercent = $this->averageScorePercent(
            $listeningAvg !== null ? (float) $listeningAvg : null,
            $vocabPercent
        );

        return Inertia::render('Profile/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'stats' => [
                'vocabulary_count' => $vocabularyCount,
                'media_count' => $mediaCount,
                'average_score_percent' => $averageScorePercent,
            ],
            'history' => collect($this->buildHistory($user->id))->map(function (array $item) {
                return [
                    ...$item,
                    'completed_at' => $item['completed_at']?->toIso8601String(),
                ];
            })->all(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHistory(int $userId): array
    {
        $listening = ListeningAttempt::query()
            ->where('user_id', $userId)
            ->with(['assessment:id,title,type'])
            ->orderByDesc('completed_at')
            ->limit(30)
            ->get();

        $vocabSessions = QuizAttempt::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get(['correct', 'created_at'])
            ->groupBy(fn (QuizAttempt $attempt) => $attempt->created_at->toDateString())
            ->map(fn ($group, string $sessionDate) => (object) [
                'session_date' => $sessionDate,
                'score' => $group->where('correct', true)->count(),
                'total' => $group->count(),
                'completed_at' => $group->max('created_at'),
            ])
            ->sortByDesc('session_date')
            ->take(30)
            ->values();

        $items = new Collection;

        foreach ($listening as $attempt) {
            $assessment = $attempt->assessment;
            if (! $assessment) {
                continue;
            }

            $items->push([
                'id' => 'listening-'.$attempt->id,
                'kind' => 'listening',
                'title' => $assessment->title,
                'type' => $assessment->type,
                'score' => $attempt->score,
                'total' => $attempt->total,
                'completed_at' => $attempt->completed_at,
            ]);
        }

        foreach ($vocabSessions as $session) {
            $date = Carbon::parse($session->session_date);
            $items->push([
                'id' => 'vocab-'.$session->session_date,
                'kind' => 'vocab',
                'title' => 'Vocabulary Quiz — '.$date->format('d/m/Y'),
                'type' => 'quiz',
                'score' => (int) $session->score,
                'total' => (int) $session->total,
                'completed_at' => Carbon::parse($session->completed_at),
            ]);
        }

        return $items
            ->sortByDesc(fn (array $item) => $item['completed_at']?->timestamp ?? 0)
            ->take(30)
            ->values()
            ->all();
    }

    private function averageScorePercent(?float $listeningAvg, ?float $vocabPercent): ?float
    {
        $scores = array_filter(
            [$listeningAvg, $vocabPercent],
            fn ($v) => $v !== null
        );

        if ($scores === []) {
            return null;
        }

        return round(array_sum($scores) / count($scores), 1);
    }
}
