<?php

use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Support\Text;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE dictionary_entries ALTER COLUMN word TYPE VARCHAR(255)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE dictionary_entries MODIFY word VARCHAR(255)');
        }

        if (! Schema::hasTable('vocabularies')) {
            return;
        }

        $rows = DB::table('vocabularies')
            ->select('id', 'user_id', 'word', 'phonetic', 'meanings')
            ->orderBy('id')
            ->get();

        $byWord = [];
        foreach ($rows as $row) {
            $word = Text::lower(trim((string) $row->word));
            if ($word === '') {
                continue;
            }
            $byWord[$word] ??= [
                'users' => [],
                'phonetic' => null,
                'meanings' => [],
                'examples' => [],
            ];
            $byWord[$word]['users'][$row->user_id] = true;
            if ($byWord[$word]['phonetic'] === null && $row->phonetic) {
                $byWord[$word]['phonetic'] = $row->phonetic;
            }

            $meanings = is_string($row->meanings)
                ? json_decode($row->meanings, true)
                : $row->meanings;
            if (is_array($meanings)) {
                foreach ($meanings as $meaning) {
                    if (is_array($meaning)) {
                        $byWord[$word]['meanings'][] = $meaning;
                    }
                }
            }

            if (Schema::hasTable('vocabulary_examples')) {
                foreach (DB::table('vocabulary_examples')->where('vocabulary_id', $row->id)->pluck('example') as $example) {
                    if (is_string($example) && trim($example) !== '') {
                        $byWord[$word]['examples'][] = trim($example);
                    }
                }
            }
        }

        foreach ($byWord as $word => $bundle) {
            $userCount = count($bundle['users']);
            $payloadMeanings = $this->mergeMeanings($bundle['meanings'], $bundle['examples']);
            $payload = [
                'phonetic' => $bundle['phonetic'],
                'audio_url' => null,
                'source' => 'user_save',
                'meanings' => $payloadMeanings,
                'synonyms' => $this->collectRelated($payloadMeanings, 'synonyms'),
                'antonyms' => $this->collectRelated($payloadMeanings, 'antonyms'),
            ];

            $existing = DB::table('dictionary_entries')->where('word', $word)->first();

            if ($existing === null) {
                $entry = DictionaryEntry::createFromPayload($word, $payload);
                $entry->saveCount = max(1, $userCount);
                $this->persistEntry($entry);

                continue;
            }

            $saveCount = max((int) $existing->save_count, $userCount);

            if ((bool) $existing->is_curated) {
                DB::table('dictionary_entries')->where('id', $existing->id)->update([
                    'save_count' => $saveCount,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $entry = $this->loadDomainEntry((int) $existing->id, $word);
            $entry->recordSave($payload);
            $entry->saveCount = max($entry->saveCount, $saveCount);
            $this->persistEntry($entry);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE dictionary_entries ALTER COLUMN word TYPE VARCHAR(120)');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE dictionary_entries MODIFY word VARCHAR(120)');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  list<string>  $orphanExamples
     * @return list<array<string, mixed>>
     */
    private function mergeMeanings(array $meanings, array $orphanExamples): array
    {
        $normalized = DictionaryEntry::normalizeMeanings($meanings);
        $seen = [];
        $out = [];

        foreach ($normalized as $meaning) {
            $key = Text::lower(trim((string) ($meaning['definition'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $meaning;
        }

        if ($out === [] && $orphanExamples !== []) {
            $out[] = DictionaryEntry::normalizeMeaning([
                'definition' => $orphanExamples[0],
                'examples' => $orphanExamples,
            ]);
        } elseif ($out !== [] && $orphanExamples !== []) {
            $out[0]['examples'] = array_values(array_unique([
                ...DictionaryEntry::stringList($out[0]['examples'] ?? []),
                ...$orphanExamples,
            ]));
        }

        if ($out === []) {
            $out[] = DictionaryEntry::normalizeMeaning([
                'definition' => '(imported from user vocabulary)',
            ]);
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
            $terms = [...$terms, ...DictionaryEntry::stringList($meaning[$key] ?? null)];
        }

        return array_values(array_unique($terms));
    }

    private function loadDomainEntry(int $id, string $word): DictionaryEntry
    {
        $meanings = [];
        foreach (DB::table('dictionary_meanings')->where('dictionary_entry_id', $id)->orderBy('position')->get() as $meaning) {
            $meanings[] = [
                'part_of_speech' => $meaning->part_of_speech,
                'definition' => $meaning->definition,
                'examples' => DB::table('dictionary_examples')->where('dictionary_meaning_id', $meaning->id)->orderBy('position')->pluck('example')->all(),
                'synonyms' => DB::table('dictionary_synonyms')->where('dictionary_meaning_id', $meaning->id)->orderBy('position')->pluck('term')->all(),
                'antonyms' => DB::table('dictionary_antonyms')->where('dictionary_meaning_id', $meaning->id)->orderBy('position')->pluck('term')->all(),
            ];
        }

        $entryRow = DB::table('dictionary_entries')->where('id', $id)->first();

        return new DictionaryEntry(
            word: $word,
            phonetic: $entryRow->phonetic,
            audioUrl: $entryRow->audio_url,
            source: $entryRow->source,
            isCurated: (bool) $entryRow->is_curated,
            saveCount: (int) $entryRow->save_count,
            meanings: $meanings,
            synonyms: DB::table('dictionary_synonyms')->where('dictionary_entry_id', $id)->whereNull('dictionary_meaning_id')->orderBy('position')->pluck('term')->all(),
            antonyms: DB::table('dictionary_antonyms')->where('dictionary_entry_id', $id)->whereNull('dictionary_meaning_id')->orderBy('position')->pluck('term')->all(),
        );
    }

    private function persistEntry(DictionaryEntry $entry): void
    {
        $now = now();
        $entryId = DB::table('dictionary_entries')->where('word', $entry->word)->value('id');

        if ($entryId === null) {
            $entryId = DB::table('dictionary_entries')->insertGetId([
                'word' => $entry->word,
                'phonetic' => $entry->phonetic,
                'audio_url' => $entry->audioUrl,
                'source' => $entry->source,
                'is_curated' => $entry->isCurated,
                'save_count' => $entry->saveCount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('dictionary_entries')->where('id', $entryId)->update([
                'phonetic' => $entry->phonetic,
                'audio_url' => $entry->audioUrl,
                'source' => $entry->source,
                'is_curated' => $entry->isCurated,
                'save_count' => $entry->saveCount,
                'updated_at' => $now,
            ]);
        }

        $meaningIds = DB::table('dictionary_meanings')->where('dictionary_entry_id', $entryId)->pluck('id');
        if ($meaningIds->isNotEmpty()) {
            DB::table('dictionary_examples')->whereIn('dictionary_meaning_id', $meaningIds)->delete();
            DB::table('dictionary_synonyms')->whereIn('dictionary_meaning_id', $meaningIds)->delete();
            DB::table('dictionary_antonyms')->whereIn('dictionary_meaning_id', $meaningIds)->delete();
            DB::table('dictionary_meanings')->where('dictionary_entry_id', $entryId)->delete();
        }
        DB::table('dictionary_synonyms')->where('dictionary_entry_id', $entryId)->delete();
        DB::table('dictionary_antonyms')->where('dictionary_entry_id', $entryId)->delete();

        foreach (array_values($entry->meanings) as $index => $meaning) {
            $definition = trim((string) ($meaning['definition'] ?? ''));
            if ($definition === '') {
                continue;
            }

            $meaningId = DB::table('dictionary_meanings')->insertGetId([
                'dictionary_entry_id' => $entryId,
                'part_of_speech' => $meaning['part_of_speech'] ?? null,
                'definition' => $definition,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_values(DictionaryEntry::stringList($meaning['examples'] ?? [])) as $exIndex => $example) {
                DB::table('dictionary_examples')->insert([
                    'dictionary_meaning_id' => $meaningId,
                    'example' => $example,
                    'position' => $exIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (array_values(DictionaryEntry::stringList($meaning['synonyms'] ?? [])) as $synIndex => $term) {
                DB::table('dictionary_synonyms')->insert([
                    'dictionary_entry_id' => $entryId,
                    'dictionary_meaning_id' => $meaningId,
                    'term' => $term,
                    'position' => $synIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach (array_values(DictionaryEntry::stringList($meaning['antonyms'] ?? [])) as $antIndex => $term) {
                DB::table('dictionary_antonyms')->insert([
                    'dictionary_entry_id' => $entryId,
                    'dictionary_meaning_id' => $meaningId,
                    'term' => $term,
                    'position' => $antIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (array_values($entry->synonyms) as $index => $term) {
            DB::table('dictionary_synonyms')->insert([
                'dictionary_entry_id' => $entryId,
                'dictionary_meaning_id' => null,
                'term' => $term,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (array_values($entry->antonyms) as $index => $term) {
            DB::table('dictionary_antonyms')->insert([
                'dictionary_entry_id' => $entryId,
                'dictionary_meaning_id' => null,
                'term' => $term,
                'position' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
