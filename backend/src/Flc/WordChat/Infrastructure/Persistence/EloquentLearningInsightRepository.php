<?php

namespace Flc\WordChat\Infrastructure\Persistence;

use App\Models\VocabularyLearningInsight as VocabularyLearningInsightModel;
use Flc\WordChat\Application\LearningInsightRepository;
use Flc\WordChat\Domain\LearningInsight;
use Flc\Shared\Support\Text;

final class EloquentLearningInsightRepository implements LearningInsightRepository
{
    public function save(LearningInsight $insight): LearningInsight
    {
        $model = VocabularyLearningInsightModel::query()->create([
            'user_id' => $insight->userId,
            'vocabulary_id' => $insight->vocabularyId,
            'word' => $insight->word,
            'insight_type' => $insight->insightType,
            'question' => $insight->question,
            'content' => $insight->content,
            'source_message_id' => $insight->sourceMessageId,
            'metadata' => $insight->metadata,
            'quiz_eligible' => $insight->quizEligible,
            'times_used_in_quiz' => $insight->timesUsedInQuiz,
        ]);

        return $this->toDomain($model);
    }

    public function listForUser(int $userId, ?string $word = null, int $limit = 50): array
    {
        $query = VocabularyLearningInsightModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)));

        if ($word !== null && trim($word) !== '') {
            $query->where('word', Text::lower(trim($word)));
        }

        return $query->get()
            ->map(fn (VocabularyLearningInsightModel $model) => $this->toDomain($model))
            ->all();
    }

    public function findForUser(int $userId, int $id): ?LearningInsight
    {
        $model = VocabularyLearningInsightModel::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function findEligibleForVocabulary(int $userId, int $vocabularyId): ?LearningInsight
    {
        $model = VocabularyLearningInsightModel::query()
            ->where('user_id', $userId)
            ->where('vocabulary_id', $vocabularyId)
            ->where('quiz_eligible', true)
            ->orderBy('times_used_in_quiz')
            ->orderByDesc('id')
            ->first();

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function incrementQuizUsage(int $id): void
    {
        VocabularyLearningInsightModel::query()
            ->whereKey($id)
            ->increment('times_used_in_quiz');
    }

    private function toDomain(VocabularyLearningInsightModel $model): LearningInsight
    {
        return new LearningInsight(
            id: (int) $model->id,
            userId: (int) $model->user_id,
            vocabularyId: $model->vocabulary_id !== null ? (int) $model->vocabulary_id : null,
            word: (string) $model->word,
            insightType: (string) $model->insight_type,
            question: $model->question,
            content: (string) $model->content,
            sourceMessageId: $model->source_message_id !== null ? (int) $model->source_message_id : null,
            metadata: is_array($model->metadata) ? $model->metadata : null,
            quizEligible: (bool) $model->quiz_eligible,
            timesUsedInQuiz: (int) $model->times_used_in_quiz,
            createdAt: $model->created_at?->toIso8601String(),
        );
    }
}
