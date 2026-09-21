<?php

namespace Flc\WordChat\Application;

use Flc\WordChat\Domain\LearningInsight;

interface LearningInsightRepository
{
    public function save(LearningInsight $insight): LearningInsight;

    /**
     * @return list<LearningInsight>
     */
    public function listForUser(int $userId, ?string $word = null, int $limit = 50): array;

    public function findForUser(int $userId, int $id): ?LearningInsight;

    public function findEligibleForVocabulary(int $userId, int $vocabularyId): ?LearningInsight;

    public function incrementQuizUsage(int $id): void;
}
