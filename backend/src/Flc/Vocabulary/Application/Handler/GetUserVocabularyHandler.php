<?php

namespace Flc\Vocabulary\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Query\GetUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;

final class GetUserVocabularyHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetUserVocabulary);

        $vocabulary = $this->vocabularies->findForUser($query->userId, $query->vocabularyId);

        return $vocabulary?->toApiArray();
    }
}
