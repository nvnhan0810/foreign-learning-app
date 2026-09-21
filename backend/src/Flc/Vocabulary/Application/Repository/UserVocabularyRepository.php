<?php

namespace Flc\Vocabulary\Application\Repository;

use Flc\Vocabulary\Domain\UserVocabulary;

interface UserVocabularyRepository
{
    /** @return list<UserVocabulary> */
    public function listForUser(int $userId): array;

    public function findForUser(int $userId, int $vocabularyId): ?UserVocabulary;

    public function findByUserAndWord(int $userId, string $word): ?UserVocabulary;

    public function save(UserVocabulary $vocabulary): UserVocabulary;

    public function deleteForUser(int $userId, int $vocabularyId): bool;
}
