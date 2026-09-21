<?php

namespace Flc\Identity\Application\Handler;

use Flc\Identity\Application\Command\UpdateAllowedEmail;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Identity\Domain\AllowedEmail;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class UpdateAllowedEmailHandler implements CommandHandler
{
    public function __construct(
        private readonly AllowedEmailRepository $allowedEmails,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof UpdateAllowedEmail);

        return $this->allowedEmails->save(new AllowedEmail(
            id: $command->id,
            pattern: strtolower(trim($command->pattern)),
            label: $command->label,
            isActive: $command->isActive,
        ));
    }
}
