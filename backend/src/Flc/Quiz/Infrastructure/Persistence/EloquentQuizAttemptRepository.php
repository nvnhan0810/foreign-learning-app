<?php

namespace Flc\Quiz\Infrastructure\Persistence;

use App\Models\QuizAttempt;
use App\Models\Vocabulary;
use Carbon\Carbon;
use Flc\Quiz\Application\Repository\QuizAttemptRepository;
use Illuminate\Support\Facades\DB;

final class EloquentQuizAttemptRepository implements QuizAttemptRepository
{
    public function record(int $userId, int $vocabularyId, string $questionType, bool $correct): int
    {
        return DB::transaction(function () use ($userId, $vocabularyId, $questionType, $correct) {
            QuizAttempt::query()->create([
                'vocabulary_id' => $vocabularyId,
                'user_id' => $userId,
                'correct' => $correct,
                'question_type' => $questionType,
            ]);

            $vocabulary = Vocabulary::query()
                ->where('user_id', $userId)
                ->where('id', $vocabularyId)
                ->firstOrFail();

            $vocabulary->times_quizzed++;
            $vocabulary->last_quizzed_at = now();

            if ($correct) {
                $vocabulary->last_correct_at = now();
            }

            $vocabulary->save();

            return (int) $vocabulary->times_quizzed;
        });
    }

    public function countTodayForUser(int $userId, string $timezone = 'Asia/Ho_Chi_Minh'): int
    {
        $start = Carbon::now($timezone)->startOfDay();
        $end = Carbon::now($timezone)->endOfDay();

        return QuizAttempt::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->count();
    }
}
