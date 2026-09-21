<?php

namespace Flc\Quiz\Application\Repository;

interface QuizAttemptRepository
{
    public function record(int $userId, int $vocabularyId, string $questionType, bool $correct): int;

    public function countTodayForUser(int $userId, string $timezone = 'Asia/Ho_Chi_Minh'): int;
}
