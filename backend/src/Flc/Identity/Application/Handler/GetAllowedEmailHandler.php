<?php

namespace Flc\Identity\Application\Handler;

use Flc\Identity\Application\Query\GetAllowedEmail;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetAllowedEmailHandler implements QueryHandler
{
    public function __construct(
        private readonly AllowedEmailRepository $allowedEmails,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetAllowedEmail);

        return $this->allowedEmails->find($query->id);
    }
}
