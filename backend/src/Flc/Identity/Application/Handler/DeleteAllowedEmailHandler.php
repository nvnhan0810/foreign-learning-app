<?php

namespace Flc\Identity\Application\Handler;

use Flc\Identity\Application\Command\DeleteAllowedEmail;
use Flc\Identity\Application\Repository\AllowedEmailRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class DeleteAllowedEmailHandler implements CommandHandler
{
    public function __construct(
        private readonly AllowedEmailRepository $allowedEmails,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteAllowedEmail);

        $this->allowedEmails->delete($command->id);

        return null;
    }
}
