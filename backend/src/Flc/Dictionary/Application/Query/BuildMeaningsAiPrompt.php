<?php

namespace Flc\Dictionary\Application\Query;

use Flc\Shared\Application\Query;

/**
 * Build an AI prompt to produce/edit meanings JSON for a word.
 *
 * @param  list<array<string, mixed>>|null  $meanings  When set, used as current JSON; otherwise resolved from vocab/dictionary.
 */
final class BuildMeaningsAiPrompt implements Query
{
    /**
     * @param  list<array<string, mixed>>|null  $meanings
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $word,
        public readonly ?array $meanings = null,
        public readonly bool $includeInsights = true,
        public readonly int $insightsLimit = 30,
    ) {}
}
