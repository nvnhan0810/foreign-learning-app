<?php

namespace Flc\Vocabulary\Application\Handler;

use App\Models\DictionaryEntry as DictionaryEntryModel;
use Flc\Dictionary\Application\Command\UpsertDictionaryOnSave;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;
use RuntimeException;

final class SaveUserVocabularyHandler implements CommandHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly CommandBus $commands,
        private readonly DictionaryEntryRepository $entries,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SaveUserVocabulary);

        $word = Text::lower(trim($command->word));
        if ($word === '') {
            return null;
        }

        $commandMeanings = $this->commandMeanings($command);
        if ($commandMeanings !== null) {
            // Extension / FE already sent meanings JSON — persist as-is, never call Free Dictionary.
            return $this->saveClientMeanings($command, $word, $commandMeanings);
        }

        $existing = $this->vocabularies->findByUserAndWord($command->userId, $word);
        if ($existing !== null) {
            if ($this->hasContentUpdate($command)) {
                return $this->updateExistingFromChat($existing, $command);
            }

            return ['vocabulary' => $existing, 'created' => false, 'backfilled' => false];
        }

        $meanings = [];
        if ($this->hasExamples($command)) {
            $meanings = DictionaryEntry::mergeExamplesIntoMeanings($meanings, $command->examples ?? []);
        }

        $this->upsertDictionary($word, $command, null, $meanings);
        $entryId = $this->dictionaryEntryId($word);
        if ($entryId === null) {
            throw new RuntimeException('Failed to upsert dictionary entry for vocabulary bookmark.');
        }

        $vocabulary = $this->vocabularies->save(new UserVocabulary(
            id: null,
            userId: $command->userId,
            dictionaryEntryId: $entryId,
            word: $word,
            phonetic: $command->phonetic,
            meanings: $meanings,
        ));

        return ['vocabulary' => $vocabulary, 'created' => true, 'backfilled' => false];
    }

    /**
     * Save meanings supplied by extension/FE. No external dictionary lookup.
     *
     * @param  list<array<string, mixed>>  $normalizedMeanings
     * @return array{vocabulary: UserVocabulary, created: bool, backfilled: false, content_updated?: bool}
     */
    private function saveClientMeanings(
        SaveUserVocabulary $command,
        string $word,
        array $normalizedMeanings,
    ): array {
        $meanings = $this->meaningsForVocabulary($normalizedMeanings);
        if ($this->hasExamples($command)) {
            $meanings = DictionaryEntry::mergeExamplesIntoMeanings($meanings, $command->examples ?? []);
        }

        $entryId = $this->persistClientDictionary($word, $command, $meanings);
        if ($entryId === null) {
            throw new RuntimeException('Failed to upsert dictionary entry for vocabulary bookmark.');
        }

        $existing = $this->vocabularies->findByUserAndWord($command->userId, $word);
        if ($existing !== null) {
            $vocabulary = $this->vocabularies->findForUser($command->userId, (int) $existing->id) ?? $existing;

            return [
                'vocabulary' => $vocabulary,
                'created' => false,
                'backfilled' => false,
                'content_updated' => true,
            ];
        }

        $vocabulary = $this->vocabularies->save(new UserVocabulary(
            id: null,
            userId: $command->userId,
            dictionaryEntryId: $entryId,
            word: $word,
            phonetic: $command->phonetic,
            meanings: $meanings,
        ));

        return ['vocabulary' => $vocabulary, 'created' => true, 'backfilled' => false];
    }

    /**
     * Persist FE meanings onto the dictionary entry (create or replace). Never fetches upstream.
     *
     * @param  list<array<string, mixed>>  $meanings
     */
    private function persistClientDictionary(
        string $word,
        SaveUserVocabulary $command,
        array $meanings,
    ): ?int {
        $synonyms = $this->stringList($command->synonyms) !== []
            ? $this->stringList($command->synonyms)
            : $this->collectRelated($meanings, 'synonyms');
        $antonyms = $this->stringList($command->antonyms) !== []
            ? $this->stringList($command->antonyms)
            : $this->collectRelated($meanings, 'antonyms');

        $existing = $this->entries->findByWord($word);
        if ($existing === null) {
            $this->entries->save(DictionaryEntry::createFromPayload($word, [
                'word' => $word,
                'phonetic' => $command->phonetic,
                'audio_url' => null,
                'meanings' => $meanings,
                'synonyms' => $synonyms,
                'antonyms' => $antonyms,
                'source' => 'user_save',
            ]));

            return $this->dictionaryEntryId($word);
        }

        if ($existing->isCurated) {
            $existing->recordSave(null);
            $this->entries->save($existing);

            return $this->dictionaryEntryId($word);
        }

        $this->entries->save(new DictionaryEntry(
            word: $word,
            phonetic: $command->phonetic ?? $existing->phonetic,
            audioUrl: $existing->audioUrl,
            source: $existing->source !== '' ? $existing->source : 'user_save',
            isCurated: false,
            saveCount: max(1, $existing->saveCount) + 1,
            meanings: DictionaryEntry::normalizeMeanings($meanings),
            synonyms: $synonyms,
            antonyms: $antonyms,
        ));

        return $this->dictionaryEntryId($word);
    }

    /**
     * @return array{vocabulary: UserVocabulary, created: false, backfilled: bool, content_updated?: bool}
     */
    private function updateExistingFromChat(UserVocabulary $existing, SaveUserVocabulary $command): array
    {
        $entryModel = DictionaryEntryModel::query()->where('word', $existing->word)->first();
        if ($entryModel === null || $entryModel->is_curated) {
            return ['vocabulary' => $existing, 'created' => false, 'backfilled' => false];
        }

        $meanings = DictionaryEntry::normalizeMeanings($existing->meanings);
        $commandMeanings = $this->commandMeanings($command);
        if ($commandMeanings !== null) {
            $meanings = DictionaryEntry::mergeMeaningsFromChat($meanings, $commandMeanings);
        }
        if ($this->hasExamples($command)) {
            $meanings = DictionaryEntry::mergeExamplesIntoMeanings($meanings, $command->examples ?? []);
        }

        $entrySynonyms = $this->mergeEntryTerms(
            $this->collectRelated($meanings, 'synonyms'),
            $this->stringList($command->synonyms),
        );
        $entryAntonyms = $this->mergeEntryTerms(
            $this->collectRelated($meanings, 'antonyms'),
            $this->stringList($command->antonyms),
        );

        $entry = new DictionaryEntry(
            word: $existing->word,
            phonetic: $command->phonetic ?? $existing->phonetic ?? $entryModel->phonetic,
            audioUrl: $entryModel->audio_url,
            source: $entryModel->source ?: 'user_save',
            isCurated: false,
            saveCount: max(1, (int) $entryModel->save_count),
            meanings: $meanings,
            synonyms: $entrySynonyms,
            antonyms: $entryAntonyms,
        );
        $this->entries->save($entry);

        $vocabulary = $this->vocabularies->findForUser($existing->userId, (int) $existing->id) ?? $existing;

        return [
            'vocabulary' => $vocabulary,
            'created' => false,
            'backfilled' => false,
            'content_updated' => true,
        ];
    }

    private function hasContentUpdate(SaveUserVocabulary $command): bool
    {
        return $this->hasExamples($command)
            || $this->commandMeanings($command) !== null
            || $command->phonetic !== null
            || $this->stringList($command->synonyms) !== []
            || $this->stringList($command->antonyms) !== [];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function commandMeanings(SaveUserVocabulary $command): ?array
    {
        if (! is_array($command->meanings) || $command->meanings === []) {
            return null;
        }

        return DictionaryEntry::normalizeMeanings($command->meanings);
    }

    private function hasExamples(SaveUserVocabulary $command): bool
    {
        return is_array($command->examples) && $command->examples !== [];
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function mergeEntryTerms(array $left, array $right): array
    {
        if ($right === []) {
            return $left;
        }

        return array_values(array_unique([...$left, ...$right]));
    }

    /**
     * @param  array<string, mixed>|null  $lookup
     * @param  list<array<string, mixed>>  $meanings
     */
    private function upsertDictionary(
        string $word,
        SaveUserVocabulary $command,
        ?array $lookup,
        array $meanings,
    ): void {
        $payload = is_array($lookup) ? $lookup : [
            'word' => $word,
            'phonetic' => $command->phonetic,
            'audio_url' => null,
            'meanings' => $meanings,
            'synonyms' => $this->collectRelated($meanings, 'synonyms'),
            'antonyms' => $this->collectRelated($meanings, 'antonyms'),
            'source' => 'user_save',
        ];
        $payload['meanings'] = $meanings;
        $payload['synonyms'] = $this->entrySynonyms($command, $meanings, $payload);
        $payload['antonyms'] = $this->entryAntonyms($command, $meanings, $payload);
        if ($command->phonetic) {
            $payload['phonetic'] = $command->phonetic;
        }

        $this->commands->dispatch(new UpsertDictionaryOnSave($word, $payload));
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function entrySynonyms(SaveUserVocabulary $command, array $meanings, array $payload): array
    {
        $fromCommand = $this->stringList($command->synonyms);
        if ($fromCommand !== []) {
            return $fromCommand;
        }

        return $this->stringList($payload['synonyms'] ?? []) !== []
            ? $this->stringList($payload['synonyms'] ?? [])
            : $this->collectRelated($meanings, 'synonyms');
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function entryAntonyms(SaveUserVocabulary $command, array $meanings, array $payload): array
    {
        $fromCommand = $this->stringList($command->antonyms);
        if ($fromCommand !== []) {
            return $fromCommand;
        }

        return $this->stringList($payload['antonyms'] ?? []) !== []
            ? $this->stringList($payload['antonyms'] ?? [])
            : $this->collectRelated($meanings, 'antonyms');
    }

    private function dictionaryEntryId(string $word): ?int
    {
        $id = DictionaryEntryModel::query()->where('word', $word)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @return list<array<string, mixed>>
     */
    private function meaningsForVocabulary(array $meanings): array
    {
        $out = [];
        foreach ($meanings as $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $examples = [];
            if (! empty($meaning['examples']) && is_array($meaning['examples'])) {
                foreach ($meaning['examples'] as $example) {
                    if (is_string($example) && trim($example) !== '') {
                        $examples[] = trim($example);
                    }
                }
            } elseif (! empty($meaning['example']) && is_string($meaning['example'])) {
                $examples[] = $meaning['example'];
            }
            $out[] = [
                'part_of_speech' => $meaning['part_of_speech'] ?? null,
                'definition' => $meaning['definition'] ?? '',
                'example' => $examples[0] ?? null,
                'examples' => $examples,
                'synonyms' => $this->stringList($meaning['synonyms'] ?? null),
                'antonyms' => $this->stringList($meaning['antonyms'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @return list<string>
     */
    private function collectRelated(array $meanings, string $key): array
    {
        $terms = [];
        foreach ($meanings as $meaning) {
            $terms = [...$terms, ...$this->stringList($meaning[$key] ?? null)];
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }
}
