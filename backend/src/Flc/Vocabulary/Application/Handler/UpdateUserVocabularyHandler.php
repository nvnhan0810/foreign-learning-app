<?php

namespace Flc\Vocabulary\Application\Handler;

use App\Models\DictionaryEntry as DictionaryEntryModel;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Vocabulary\Application\Command\UpdateUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class UpdateUserVocabularyHandler implements CommandHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly DictionaryEntryRepository $entries,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof UpdateUserVocabulary);

        $vocabulary = $this->vocabularies->findForUser($command->userId, $command->vocabularyId);
        if ($vocabulary === null) {
            throw new RuntimeException('Vocabulary not found.');
        }

        $hasContentUpdate = array_key_exists('phonetic', $command->data)
            || (array_key_exists('meanings', $command->data) && is_array($command->data['meanings']));

        if (! $hasContentUpdate) {
            return $vocabulary;
        }

        $entryModel = DictionaryEntryModel::query()->find($vocabulary->dictionaryEntryId);
        if ($entryModel === null) {
            throw new RuntimeException('Dictionary entry not found.');
        }

        if ($entryModel->is_curated) {
            throw new UnprocessableEntityHttpException('Curated dictionary entries cannot be edited from vocabulary.');
        }

        $meanings = array_key_exists('meanings', $command->data) && is_array($command->data['meanings'])
            ? DictionaryEntry::normalizeMeanings($command->data['meanings'])
            : DictionaryEntry::normalizeMeanings($vocabulary->meanings);

        $entry = new DictionaryEntry(
            word: $vocabulary->word,
            phonetic: array_key_exists('phonetic', $command->data)
                ? $command->data['phonetic']
                : $vocabulary->phonetic,
            audioUrl: $entryModel->audio_url,
            source: $entryModel->source ?: 'user_save',
            isCurated: false,
            saveCount: max(1, (int) $entryModel->save_count),
            meanings: $meanings,
            synonyms: $this->collectRelated($meanings, 'synonyms'),
            antonyms: $this->collectRelated($meanings, 'antonyms'),
        );

        $this->entries->save($entry);

        return $this->vocabularies->findForUser($command->userId, $command->vocabularyId);
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @return list<string>
     */
    private function collectRelated(array $meanings, string $key): array
    {
        $terms = [];
        foreach ($meanings as $meaning) {
            foreach (DictionaryEntry::stringList($meaning[$key] ?? null) as $term) {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }
}
