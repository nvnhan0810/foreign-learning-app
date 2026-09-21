<?php

namespace Flc\Vocabulary\Application\Handler;

use App\Models\DictionaryEntry as DictionaryEntryModel;
use Flc\Dictionary\Application\Command\UpsertDictionaryOnSave;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;
use RuntimeException;

final class SaveUserVocabularyHandler implements CommandHandler
{
    public function __construct(
        private readonly UserVocabularyRepository $vocabularies,
        private readonly QueryBus $queries,
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

        $existing = $this->vocabularies->findByUserAndWord($command->userId, $word);
        if ($existing !== null) {
            if ($this->hasContentUpdate($command)) {
                return $this->updateExistingFromChat($existing, $command);
            }

            return $this->backfillExistingBookmark($existing, $command);
        }

        /** @var array<string, mixed>|null $lookup */
        $lookup = $this->queries->ask(new LookupWord($word));
        $rawMeanings = $this->commandMeanings($command) ?? $lookup['meanings'] ?? [];
        $meanings = $this->meaningsForVocabulary(is_array($rawMeanings) ? $rawMeanings : []);
        $meanings = $this->ensureRelatedWords($meanings, is_array($lookup) ? $lookup : null);
        if ($this->hasExamples($command)) {
            $meanings = DictionaryEntry::mergeExamplesIntoMeanings($meanings, $command->examples ?? []);
        }

        $this->upsertDictionary($word, $command, $lookup, $meanings);
        $entryId = $this->dictionaryEntryId($word);
        if ($entryId === null) {
            throw new RuntimeException('Failed to upsert dictionary entry for vocabulary bookmark.');
        }

        $vocabulary = $this->vocabularies->save(new UserVocabulary(
            id: null,
            userId: $command->userId,
            dictionaryEntryId: $entryId,
            word: $word,
            phonetic: $command->phonetic ?? $lookup['phonetic'] ?? null,
            meanings: $meanings,
        ));

        return ['vocabulary' => $vocabulary, 'created' => true, 'backfilled' => false];
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
     * @return array{vocabulary: UserVocabulary, created: false, backfilled: bool}
     */
    private function backfillExistingBookmark(UserVocabulary $existing, SaveUserVocabulary $command): array
    {
        if (! $this->missingRelatedWords($existing->meanings)) {
            return ['vocabulary' => $existing, 'created' => false, 'backfilled' => false];
        }

        /** @var array<string, mixed>|null $lookup */
        $lookup = $this->queries->ask(new LookupWord($existing->word));

        $baseMeanings = $existing->meanings !== []
            ? $existing->meanings
            : (is_array($lookup['meanings'] ?? null) ? $lookup['meanings'] : ($command->meanings ?? []));

        $meanings = $this->ensureRelatedWords(
            $this->meaningsForVocabulary(is_array($baseMeanings) ? $baseMeanings : []),
            is_array($lookup) ? $lookup : null,
        );

        $beforeSyn = $this->collectRelated($existing->meanings, 'synonyms');
        $beforeAnt = $this->collectRelated($existing->meanings, 'antonyms');
        $afterSyn = $this->collectRelated($meanings, 'synonyms');
        $afterAnt = $this->collectRelated($meanings, 'antonyms');
        $gained = ($beforeSyn === [] && $afterSyn !== [])
            || ($beforeAnt === [] && $afterAnt !== []);

        if (! $gained) {
            return ['vocabulary' => $existing, 'created' => false, 'backfilled' => false];
        }

        $entryModel = DictionaryEntryModel::query()->where('word', $existing->word)->first();
        if ($entryModel !== null && ! $entryModel->is_curated) {
            $entry = new DictionaryEntry(
                word: $existing->word,
                phonetic: $existing->phonetic ?? ($lookup['phonetic'] ?? null) ?? $entryModel->phonetic,
                audioUrl: $entryModel->audio_url,
                source: $entryModel->source ?: 'user_save',
                isCurated: false,
                saveCount: max(1, (int) $entryModel->save_count),
                meanings: DictionaryEntry::normalizeMeanings($meanings),
                synonyms: $this->collectRelated($meanings, 'synonyms'),
                antonyms: $this->collectRelated($meanings, 'antonyms'),
            );
            $this->entries->save($entry);
        } else {
            $this->upsertDictionary($existing->word, $command, $lookup, $meanings);
        }

        $vocabulary = $this->vocabularies->findForUser($existing->userId, (int) $existing->id) ?? $existing;

        return ['vocabulary' => $vocabulary, 'created' => false, 'backfilled' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     */
    private function missingRelatedWords(array $meanings): bool
    {
        return $this->collectRelated($meanings, 'synonyms') === []
            || $this->collectRelated($meanings, 'antonyms') === [];
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
     * @param  array<string, mixed>|null  $lookup
     * @return list<array<string, mixed>>
     */
    private function ensureRelatedWords(array $meanings, ?array $lookup): array
    {
        if ($meanings === [] || $lookup === null) {
            return $meanings;
        }

        $entrySynonyms = $this->collectRelatedFromPayload($lookup, 'synonyms');
        $entryAntonyms = $this->collectRelatedFromPayload($lookup, 'antonyms');
        $lookupMeanings = is_array($lookup['meanings'] ?? null) ? $lookup['meanings'] : [];

        foreach ($meanings as $index => $meaning) {
            $lookupMeaning = is_array($lookupMeanings[$index] ?? null) ? $lookupMeanings[$index] : null;

            if ($this->stringList($meaning['synonyms'] ?? null) === []) {
                $fromLookupMeaning = $lookupMeaning !== null
                    ? $this->stringList($lookupMeaning['synonyms'] ?? null)
                    : [];
                $meanings[$index]['synonyms'] = $fromLookupMeaning !== []
                    ? $fromLookupMeaning
                    : ($index === 0 ? $entrySynonyms : []);
            }

            if ($this->stringList($meaning['antonyms'] ?? null) === []) {
                $fromLookupMeaning = $lookupMeaning !== null
                    ? $this->stringList($lookupMeaning['antonyms'] ?? null)
                    : [];
                $meanings[$index]['antonyms'] = $fromLookupMeaning !== []
                    ? $fromLookupMeaning
                    : ($index === 0 ? $entryAntonyms : []);
            }
        }

        if ($this->stringList($meanings[0]['synonyms'] ?? null) === [] && $entrySynonyms !== []) {
            $meanings[0]['synonyms'] = $entrySynonyms;
        }
        if ($this->stringList($meanings[0]['antonyms'] ?? null) === [] && $entryAntonyms !== []) {
            $meanings[0]['antonyms'] = $entryAntonyms;
        }

        return $meanings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function collectRelatedFromPayload(array $payload, string $key): array
    {
        $terms = $this->stringList($payload[$key] ?? null);
        foreach ($payload['meanings'] ?? [] as $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $terms = [...$terms, ...$this->stringList($meaning[$key] ?? null)];
        }

        return array_values(array_unique($terms));
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
