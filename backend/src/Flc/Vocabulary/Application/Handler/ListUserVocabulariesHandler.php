<?php

namespace Flc\Vocabulary\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Query\ListUserVocabularies;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;

final class ListUserVocabulariesHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof ListUserVocabularies);

        return array_map(
            fn ($item) => $item->toApiArray(),
            $this->vocabularies->listForUser($query->userId)
        );
    }
}
