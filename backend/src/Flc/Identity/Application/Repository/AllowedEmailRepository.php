<?php

namespace Flc\Identity\Application\Repository;

use Flc\Identity\Domain\AllowedEmail;
use Flc\Shared\Application\PaginatedResult;

interface AllowedEmailRepository
{
    /**
     * @return list<string>
     */
    public function activePatterns(): array;

    public function countActive(): int;

    /**
     * @return PaginatedResult<AllowedEmail>
     */
    public function paginate(int $perPage = 20): PaginatedResult;

    public function find(int $id): ?AllowedEmail;

    public function save(AllowedEmail $entry): AllowedEmail;

    public function delete(int $id): void;
}
