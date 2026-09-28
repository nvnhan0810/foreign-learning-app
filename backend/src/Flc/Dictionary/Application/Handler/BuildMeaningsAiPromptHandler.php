<?php

namespace Flc\Dictionary\Application\Handler;

use Flc\Dictionary\Application\DictionaryMeaningsEditor;
use Flc\Dictionary\Application\Query\BuildMeaningsAiPrompt;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Application\QueryHandler;
use Flc\Vocabulary\Application\Query\FindUserVocabularyByWord;
use Flc\WordChat\Application\Query\ListLearningInsights;

final class BuildMeaningsAiPromptHandler implements QueryHandler
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    /**
     * @return array{
     *   word: string,
     *   prompt: string,
     *   current_meanings: list<array<string, mixed>>,
     *   meanings_json: string,
     *   learner_context: list<string>,
     *   insights: list<array<string, mixed>>
     * }
     */
    public function handle(Query $query): mixed
    {
        assert($query instanceof BuildMeaningsAiPrompt);

        $word = trim($query->word);
        $meanings = $query->meanings !== null
            ? $query->meanings
            : $this->resolveCurrentMeanings($query->userId, $word);

        $insights = [];
        $learnerContext = [];
        if ($query->includeInsights && $word !== '') {
            $insights = $this->queries->ask(new ListLearningInsights(
                userId: $query->userId,
                word: $word,
                limit: $query->insightsLimit,
            ));
            if (! is_array($insights)) {
                $insights = [];
            }
            $learnerContext = $this->formatInsightsAsContext($insights);
        }

        $normalized = DictionaryMeaningsEditor::toPromptMeaningsPayload($meanings);

        return [
            'word' => $word,
            'prompt' => DictionaryMeaningsEditor::aiPrompt($word, $normalized, $learnerContext),
            'current_meanings' => $normalized,
            'meanings_json' => DictionaryMeaningsEditor::toPrettyJson($normalized),
            'learner_context' => $learnerContext,
            'insights' => $insights,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveCurrentMeanings(int $userId, string $word): array
    {
        if ($word === '') {
            return [];
        }

        $vocab = $this->queries->ask(new FindUserVocabularyByWord($userId, $word));
        if (is_array($vocab) && isset($vocab['meanings']) && is_array($vocab['meanings']) && $vocab['meanings'] !== []) {
            return $vocab['meanings'];
        }

        $resolved = $this->queries->ask(new ResolveLookupWord($word));
        if (! is_array($resolved)) {
            return [];
        }

        $dictionary = $resolved['dictionary'] ?? null;
        if (! is_array($dictionary)) {
            return [];
        }

        $meanings = $dictionary['meanings'] ?? [];

        return is_array($meanings) ? $meanings : [];
    }

    /**
     * @param  list<array<string, mixed>>  $insights
     * @return list<string>
     */
    private function formatInsightsAsContext(array $insights): array
    {
        $notes = [];
        foreach ($insights as $insight) {
            if (! is_array($insight)) {
                continue;
            }

            $type = trim((string) ($insight['insight_type'] ?? 'note'));
            $content = trim((string) ($insight['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $question = trim((string) ($insight['question'] ?? ''));
            $line = '['.$type.'] '.$content;
            if ($question !== '') {
                $line .= ' (Q: '.$question.')';
            }
            $notes[] = $line;
        }

        return $notes;
    }
}
