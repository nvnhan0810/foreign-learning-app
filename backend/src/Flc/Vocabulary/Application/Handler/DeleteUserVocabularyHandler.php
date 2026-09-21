<?php

namespace Flc\Vocabulary\Application\Handler;

use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Vocabulary\Application\Command\DeleteUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;

final class DeleteUserVocabularyHandler implements CommandHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof DeleteUserVocabulary);

        return $this->vocabularies->deleteForUser($command->userId, $command->vocabularyId);
    }
}
