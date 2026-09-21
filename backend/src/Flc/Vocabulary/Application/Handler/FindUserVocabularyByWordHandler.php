<?php

namespace Flc\Vocabulary\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Query\FindUserVocabularyByWord;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;

final class FindUserVocabularyByWordHandler implements QueryHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof FindUserVocabularyByWord);

        $word = Text::lower(trim($query->word));
        if ($word === '') {
            return null;
        }

        return $this->vocabularies->findByUserAndWord($query->userId, $word)?->toApiArray();
    }
}
