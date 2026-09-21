<?php

namespace Flc\Dictionary\Infrastructure\Persistence;

use App\Models\DictionaryAntonym;
use App\Models\DictionaryEntry as DictionaryEntryModel;
use App\Models\DictionaryExample;
use App\Models\DictionaryMeaning;
use App\Models\DictionarySynonym;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Illuminate\Support\Facades\DB;

final class EloquentDictionaryEntryRepository implements DictionaryEntryRepository
{
    public function findByWord(string $word): ?DictionaryEntry
    {
        $model = DictionaryEntryModel::query()
            ->where('word', $word)
            ->with([
                'meanings.examples',
                'meanings.synonyms',
                'meanings.antonyms',
                'synonyms',
                'antonyms',
            ])
            ->first();

        if (! $model) {
            return null;
        }

        $meanings = [];
        foreach ($model->meanings as $meaning) {
            $meanings[] = [
                'part_of_speech' => $meaning->part_of_speech,
                'definition' => $meaning->definition,
                'examples' => $meaning->examples->pluck('example')->all(),
                'synonyms' => $meaning->synonyms->pluck('term')->all(),
                'antonyms' => $meaning->antonyms->pluck('term')->all(),
            ];
        }

        return new DictionaryEntry(
            word: $model->word,
            phonetic: $model->phonetic,
            audioUrl: $model->audio_url,
            source: $model->source,
            isCurated: (bool) $model->is_curated,
            saveCount: (int) $model->save_count,
            meanings: $meanings,
            synonyms: $model->synonyms->whereNull('dictionary_meaning_id')->pluck('term')->values()->all(),
            antonyms: $model->antonyms->whereNull('dictionary_meaning_id')->pluck('term')->values()->all(),
        );
    }

    public function save(DictionaryEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $model = DictionaryEntryModel::query()->updateOrCreate(
                ['word' => $entry->word],
                [
                    'phonetic' => $entry->phonetic,
                    'audio_url' => $entry->audioUrl,
                    'source' => $entry->source,
                    'is_curated' => $entry->isCurated,
                    'save_count' => $entry->saveCount,
                ]
            );

            $this->replaceChildren($model, $entry->meanings, $entry->synonyms, $entry->antonyms);
        });
    }

    public function deleteByWord(string $word): void
    {
        DictionaryEntryModel::query()->where('word', $word)->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  list<string>  $synonyms
     * @param  list<string>  $antonyms
     */
    private function replaceChildren(DictionaryEntryModel $entry, array $meanings, array $synonyms, array $antonyms): void
    {
        $entry->meanings()->each(function (DictionaryMeaning $meaning) {
            $meaning->examples()->delete();
            $meaning->synonyms()->delete();
            $meaning->antonyms()->delete();
            $meaning->delete();
        });
        $entry->synonyms()->delete();
        $entry->antonyms()->delete();

        foreach (array_values($meanings) as $index => $meaning) {
            if (! is_array($meaning)) {
                continue;
            }
            $definition = trim((string) ($meaning['definition'] ?? ''));
            if ($definition === '') {
                continue;
            }

            $row = DictionaryMeaning::query()->create([
                'dictionary_entry_id' => $entry->id,
                'part_of_speech' => $meaning['part_of_speech'] ?? null,
                'definition' => $definition,
                'position' => $index,
            ]);

            foreach (array_values(DictionaryEntry::stringList($meaning['examples'] ?? [])) as $exIndex => $example) {
                DictionaryExample::query()->create([
                    'dictionary_meaning_id' => $row->id,
                    'example' => $example,
                    'position' => $exIndex,
                ]);
            }

            foreach (array_values(DictionaryEntry::stringList($meaning['synonyms'] ?? [])) as $synIndex => $term) {
                DictionarySynonym::query()->create([
                    'dictionary_entry_id' => $entry->id,
                    'dictionary_meaning_id' => $row->id,
                    'term' => $term,
                    'position' => $synIndex,
                ]);
            }

            foreach (array_values(DictionaryEntry::stringList($meaning['antonyms'] ?? [])) as $antIndex => $term) {
                DictionaryAntonym::query()->create([
                    'dictionary_entry_id' => $entry->id,
                    'dictionary_meaning_id' => $row->id,
                    'term' => $term,
                    'position' => $antIndex,
                ]);
            }
        }

        foreach (array_values(DictionaryEntry::stringList($synonyms)) as $index => $term) {
            DictionarySynonym::query()->create([
                'dictionary_entry_id' => $entry->id,
                'dictionary_meaning_id' => null,
                'term' => $term,
                'position' => $index,
            ]);
        }

        foreach (array_values(DictionaryEntry::stringList($antonyms)) as $index => $term) {
            DictionaryAntonym::query()->create([
                'dictionary_entry_id' => $entry->id,
                'dictionary_meaning_id' => null,
                'term' => $term,
                'position' => $index,
            ]);
        }
    }
}
