<?php

namespace Flc\Listening\Application\Repository;

use Flc\Listening\Domain\ListeningAssessment;
use Flc\Listening\Domain\ListeningAttempt;
use Flc\Listening\Domain\ListeningQuestion;

interface ListeningAssessmentRepository
{
    public function questionBankCount(int $mediaItemId): int;

    /**
     * @return list<ListeningQuestion>
     */
    public function pickRandomQuestions(int $mediaItemId, int $count): array;

    /**
     * @return list<ListeningQuestion> ordered by bank order
     */
    public function questionsForMediaItem(int $mediaItemId): array;

    /**
     * @param  list<int>  $ids
     * @return list<ListeningQuestion> ordered to match $ids
     */
    public function findQuestionsByIds(int $mediaItemId, array $ids): array;

    /**
     * @return list<ListeningQuestion> the session's questions, respecting stored question_ids order
     */
    public function questionsForAssessment(ListeningAssessment $assessment): array;

    public function findUnfinishedAssessment(int $mediaItemId, int $userId, string $type): ?ListeningAssessment;

    /**
     * @param  array{mediaItemId: int, userId: int, type: string, title: string, description: ?string, questionCount: int, questionIds: list<int>, timeLimitMinutes: int, status: string}  $data
     */
    public function createAssessment(array $data): ListeningAssessment;

    public function findAssessmentForUser(int $id, int $userId): ?ListeningAssessment;

    public function updateAssessmentQuestions(int $assessmentId, array $questionIds): void;

    /**
     * @param  array{listeningAssessmentId: int, mediaItemId: int, type: string, userId: int, score: int, total: int, percentage: float, answers: list<array<string, mixed>>, startedAt: ?string}  $data
     */
    public function recordAttempt(array $data): ListeningAttempt;

    /**
     * @return list<ListeningAttempt>
     */
    public function listAttemptsForUser(int $assessmentId, int $userId): array;

    public function hasCompletedAttempt(int $assessmentId, int $userId): bool;

    public function latestAttemptForUser(int $assessmentId, int $userId): ?ListeningAttempt;

    /**
     * Replace the whole question bank for a media item with freshly generated questions.
     *
     * @param  list<array<string, mixed>>  $questions
     */
    public function replaceQuestionBank(int $mediaItemId, array $questions): void;
}
