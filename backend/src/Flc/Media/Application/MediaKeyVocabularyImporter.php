<?php

namespace Flc\Media\Application;

use Flc\Shared\Application\CommandBus;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Shared\Support\Text;

/**
 * Imports key vocabulary discovered during content analysis into the
 * media owner's personal vocabulary, via the Vocabulary bounded context.
 */
final class MediaKeyVocabularyImporter
{
    private const MAX_WORDS = 15;

    public function __construct(private readonly CommandBus $commands) {}

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{imported: int, skipped: int, words: list<string>}
     */
    public function importFromAnalysis(int $userId, array $analysis): array
    {
        $entries = $analysis['key_vocabulary'] ?? [];

        if (! is_array($entries) || $entries === []) {
            return ['imported' => 0, 'skipped' => 0, 'words' => []];
        }

        $imported = 0;
        $skipped = 0;
        $words = [];

        foreach (array_slice($entries, 0, self::MAX_WORDS) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $word = Text::lower(trim((string) ($entry['word'] ?? '')));

            if ($word === '' || ! preg_match('/^[a-z][a-z\'-]*$/', $word)) {
                continue;
            }

            /** @var array{vocabulary: mixed, created: bool}|null $result */
            $result = $this->commands->dispatch(new SaveUserVocabulary(
                userId: $userId,
                word: $word,
                meanings: $this->buildMeanings($entry),
            ));

            if ($result === null) {
                continue;
            }

            if ($result['created']) {
                $imported++;
                $words[] = $word;
            } else {
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'words' => $words];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>|null
     */
    private function buildMeanings(array $entry): ?array
    {
        $definition = trim((string) ($entry['definition'] ?? ''));

        if ($definition === '') {
            return null;
        }

        return [[
            'part_of_speech' => $entry['part_of_speech'] ?? null,
            'definition' => $definition,
            'example' => $entry['example'] ?? null,
        ]];
    }
}
