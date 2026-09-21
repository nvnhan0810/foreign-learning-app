<?php

namespace Flc\Listening\Infrastructure\Persistence;

use App\Models\ListeningAssessment as EloquentAssessment;
use App\Models\ListeningAttempt as EloquentAttempt;
use App\Models\ListeningQuestion as EloquentQuestion;
use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Listening\Domain\ListeningAttempt;
use Flc\Listening\Domain\ListeningQuestion;
use Illuminate\Support\Facades\DB;

final class EloquentListeningAssessmentRepository implements ListeningAssessmentRepository
{
    public function questionBankCount(int $mediaItemId): int
    {
        return EloquentQuestion::query()
            ->where('media_item_id', $mediaItemId)
            ->count();
    }

    public function pickRandomQuestions(int $mediaItemId, int $count): array
    {
        $models = EloquentQuestion::query()
            ->where('media_item_id', $mediaItemId)
            ->inRandomOrder()
            ->limit($count)
            ->get()
            ->shuffle()
            ->values();

        return $models->map(fn (EloquentQuestion $q) => self::questionToDomain($q))->all();
    }

    public function questionsForMediaItem(int $mediaItemId): array
    {
        return EloquentQuestion::query()
            ->where('media_item_id', $mediaItemId)
            ->orderBy('order')
            ->get()
            ->map(fn (EloquentQuestion $q) => self::questionToDomain($q))
            ->all();
    }

    public function findQuestionsByIds(int $mediaItemId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = EloquentQuestion::query()
            ->where('media_item_id', $mediaItemId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($ids as $id) {
            $model = $byId->get($id);

            if ($model !== null) {
                $ordered[] = self::questionToDomain($model);
            }
        }

        return $ordered;
    }

    public function questionsForAssessment(ListeningAssessment $assessment): array
    {
        $ids = $assessment->questionIds;

        if ($ids === []) {
            return $this->questionsForMediaItem($assessment->mediaItemId);
        }

        return $this->findQuestionsByIds($assessment->mediaItemId, $ids);
    }

    public function findUnfinishedAssessment(int $mediaItemId, int $userId, string $type): ?ListeningAssessment
    {
        $model = EloquentAssessment::query()
            ->where('media_item_id', $mediaItemId)
            ->where('user_id', $userId)
            ->where('type', $type)
            ->where('status', ListeningAssessment::STATUS_READY)
            ->whereDoesntHave('attempts', fn ($query) => $query->where('user_id', $userId))
            ->latest('created_at')
            ->first();

        return $model ? self::assessmentToDomain($model) : null;
    }

    public function createAssessment(array $data): ListeningAssessment
    {
        $model = EloquentAssessment::query()->create([
            'media_item_id' => $data['mediaItemId'],
            'user_id' => $data['userId'],
            'type' => $data['type'],
            'title' => $data['title'],
            'description' => $data['description'],
            'question_count' => $data['questionCount'],
            'question_ids' => $data['questionIds'],
            'time_limit_minutes' => $data['timeLimitMinutes'],
            'status' => $data['status'],
            'generated_at' => now(),
        ]);

        return self::assessmentToDomain($model);
    }

    public function findAssessmentForUser(int $id, int $userId): ?ListeningAssessment
    {
        $model = EloquentAssessment::query()
            ->whereKey($id)
            ->where('user_id', $userId)
            ->first();

        return $model ? self::assessmentToDomain($model) : null;
    }

    public function updateAssessmentQuestions(int $assessmentId, array $questionIds): void
    {
        EloquentAssessment::query()->whereKey($assessmentId)->update([
            'question_ids' => $questionIds,
            'question_count' => count($questionIds),
        ]);
    }

    public function recordAttempt(array $data): ListeningAttempt
    {
        $model = EloquentAttempt::query()->create([
            'listening_assessment_id' => $data['listeningAssessmentId'],
            'media_item_id' => $data['mediaItemId'],
            'type' => $data['type'],
            'user_id' => $data['userId'],
            'score' => $data['score'],
            'total' => $data['total'],
            'percentage' => $data['percentage'],
            'answers' => $data['answers'],
            'started_at' => $data['startedAt'] ?? now(),
            'completed_at' => now(),
        ]);

        return self::attemptToDomain($model);
    }

    public function listAttemptsForUser(int $assessmentId, int $userId): array
    {
        return EloquentAttempt::query()
            ->where('listening_assessment_id', $assessmentId)
            ->where('user_id', $userId)
            ->orderByDesc('completed_at')
            ->get(['id', 'listening_assessment_id', 'media_item_id', 'type', 'user_id', 'score', 'total', 'percentage', 'answers', 'started_at', 'completed_at'])
            ->map(fn (EloquentAttempt $a) => self::attemptToDomain($a))
            ->all();
    }

    public function hasCompletedAttempt(int $assessmentId, int $userId): bool
    {
        return EloquentAttempt::query()
            ->where('listening_assessment_id', $assessmentId)
            ->where('user_id', $userId)
            ->exists();
    }

    public function latestAttemptForUser(int $assessmentId, int $userId): ?ListeningAttempt
    {
        $model = EloquentAttempt::query()
            ->where('listening_assessment_id', $assessmentId)
            ->where('user_id', $userId)
            ->latest('completed_at')
            ->first();

        return $model ? self::attemptToDomain($model) : null;
    }

    public function replaceQuestionBank(int $mediaItemId, array $questions): void
    {
        DB::transaction(function () use ($mediaItemId, $questions) {
            EloquentQuestion::query()->where('media_item_id', $mediaItemId)->delete();

            foreach ($questions as $index => $question) {
                EloquentQuestion::query()->create([
                    'media_item_id' => $mediaItemId,
                    'order' => $index + 1,
                    'question_type' => $question['question_type'],
                    'prompt' => $question['prompt'],
                    'options' => $question['options'] ?? null,
                    'correct_answer' => $question['correct_answer'],
                    'explanation' => $question['explanation'] ?? null,
                    'audio_start_seconds' => $question['audio_start_seconds'] ?? null,
                    'audio_end_seconds' => $question['audio_end_seconds'] ?? null,
                ]);
            }
        });
    }

    public static function assessmentToDomain(EloquentAssessment $model): ListeningAssessment
    {
        return new ListeningAssessment(
            id: $model->id,
            mediaItemId: $model->media_item_id,
            userId: $model->user_id,
            type: $model->type,
            title: $model->title,
            description: $model->description,
            questionCount: (int) $model->question_count,
            questionIds: array_map('intval', $model->question_ids ?? []),
            timeLimitMinutes: (int) $model->time_limit_minutes,
            status: $model->status,
            generatedAt: $model->generated_at?->toIso8601String(),
        );
    }

    public static function questionToDomain(EloquentQuestion $model): ListeningQuestion
    {
        return new ListeningQuestion(
            id: $model->id,
            mediaItemId: $model->media_item_id,
            order: (int) $model->order,
            questionType: $model->question_type,
            prompt: $model->prompt,
            options: is_array($model->options) ? array_values($model->options) : null,
            correctAnswer: $model->correct_answer,
            explanation: $model->explanation,
            audioStartSeconds: $model->audio_start_seconds !== null ? (int) $model->audio_start_seconds : null,
            audioEndSeconds: $model->audio_end_seconds !== null ? (int) $model->audio_end_seconds : null,
        );
    }

    public static function attemptToDomain(EloquentAttempt $model): ListeningAttempt
    {
        return new ListeningAttempt(
            id: $model->id,
            listeningAssessmentId: $model->listening_assessment_id,
            mediaItemId: $model->media_item_id,
            type: $model->type,
            userId: $model->user_id,
            score: (int) $model->score,
            total: (int) $model->total,
            percentage: (float) $model->percentage,
            answers: is_array($model->answers) ? $model->answers : [],
            startedAt: $model->started_at?->toIso8601String(),
            completedAt: $model->completed_at?->toIso8601String(),
        );
    }
}
