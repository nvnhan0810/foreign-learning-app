<?php

namespace Flc\WordChat\Application\Handler;

use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;
use Flc\WordChat\Application\LearningInsightRepository;
use Flc\WordChat\Application\Query\ListLearningInsights;

final class ListLearningInsightsHandler implements QueryHandler
{
    public function __construct(
        private readonly LearningInsightRepository $insights,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof ListLearningInsights);

        $items = $this->insights->listForUser(
            userId: $query->userId,
            word: $query->word,
            limit: $query->limit,
        );

        return array_map(
            fn ($insight) => $insight->toApiArray(),
            $items,
        );
    }
}
