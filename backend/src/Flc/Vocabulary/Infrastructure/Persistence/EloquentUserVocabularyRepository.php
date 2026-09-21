<?php

namespace Flc\Vocabulary\Infrastructure\Persistence;

use App\Models\DictionaryEntry as DictionaryEntryModel;
use App\Models\Vocabulary;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Flc\Vocabulary\Domain\UserVocabulary;
use Illuminate\Support\Facades\DB;

final class EloquentUserVocabularyRepository implements UserVocabularyRepository
{
    private const ENTRY_RELATIONS = [
        'dictionaryEntry.meanings.examples',
        'dictionaryEntry.meanings.synonyms',
        'dictionaryEntry.meanings.antonyms',
        'dictionaryEntry.synonyms',
        'dictionaryEntry.antonyms',
    ];

    public function listForUser(int $userId): array
    {
        return Vocabulary::query()
            ->where('user_id', $userId)
            ->with(self::ENTRY_RELATIONS)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Vocabulary $model) => $this->toDomain($model))
            ->all();
    }

    public function findForUser(int $userId, int $vocabularyId): ?UserVocabulary
    {
        $model = Vocabulary::query()
            ->where('user_id', $userId)
            ->where('id', $vocabularyId)
            ->with(self::ENTRY_RELATIONS)
            ->first();

        return $model ? $this->toDomain($model) : null;
    }

    public function findByUserAndWord(int $userId, string $word): ?UserVocabulary
    {
        $model = Vocabulary::query()
            ->where('user_id', $userId)
            ->whereHas('dictionaryEntry', fn ($q) => $q->where('word', $word))
            ->with(self::ENTRY_RELATIONS)
            ->first();

        return $model ? $this->toDomain($model) : null;
    }

    public function save(UserVocabulary $vocabulary): UserVocabulary
    {
        return DB::transaction(function () use ($vocabulary) {
            if ($vocabulary->id === null) {
                $model = Vocabulary::query()->create([
                    'user_id' => $vocabulary->userId,
                    'dictionary_entry_id' => $vocabulary->dictionaryEntryId,
                ]);
            } else {
                $model = Vocabulary::query()
                    ->where('user_id', $vocabulary->userId)
                    ->where('id', $vocabulary->id)
                    ->firstOrFail();
                $model->update([
                    'dictionary_entry_id' => $vocabulary->dictionaryEntryId,
                ]);
            }

            return $this->toDomain($model->fresh(self::ENTRY_RELATIONS));
        });
    }

    public function deleteForUser(int $userId, int $vocabularyId): bool
    {
        $deleted = Vocabulary::query()
            ->where('user_id', $userId)
            ->where('id', $vocabularyId)
            ->delete();

        return $deleted > 0;
    }

    private function toDomain(Vocabulary $model): UserVocabulary
    {
        /** @var DictionaryEntryModel|null $entry */
        $entry = $model->dictionaryEntry;
        $payload = $entry ? $this->entryToClientPayload($entry) : [
            'word' => '',
            'phonetic' => null,
            'meanings' => [],
            'examples' => [],
        ];

        return new UserVocabulary(
            id: $model->id,
            userId: $model->user_id,
            dictionaryEntryId: (int) $model->dictionary_entry_id,
            word: (string) ($payload['word'] ?? ''),
            phonetic: $payload['phonetic'] ?? null,
            meanings: is_array($payload['meanings'] ?? null) ? $payload['meanings'] : [],
            examples: is_array($payload['examples'] ?? null) ? $payload['examples'] : [],
            timesQuizzed: (int) $model->times_quizzed,
            lastQuizzedAt: optional($model->last_quizzed_at)?->toISOString(),
            lastCorrectAt: optional($model->last_correct_at)?->toISOString(),
            createdAt: optional($model->created_at)?->toISOString(),
            updatedAt: optional($model->updated_at)?->toISOString(),
        );
    }

    /**
     * @return array{word: string, phonetic: ?string, meanings: list<array<string, mixed>>, examples: list<array<string, mixed>>}
     */
    private function entryToClientPayload(DictionaryEntryModel $model): array
    {
        $domain = new DictionaryEntry(
            word: $model->word,
            phonetic: $model->phonetic,
            audioUrl: $model->audio_url,
            source: $model->source,
            isCurated: (bool) $model->is_curated,
            saveCount: (int) $model->save_count,
            meanings: $model->meanings->map(fn ($meaning) => [
                'part_of_speech' => $meaning->part_of_speech,
                'definition' => $meaning->definition,
                'examples' => $meaning->examples->pluck('example')->all(),
                'synonyms' => $meaning->synonyms->pluck('term')->all(),
                'antonyms' => $meaning->antonyms->pluck('term')->all(),
            ])->values()->all(),
            synonyms: $model->synonyms->whereNull('dictionary_meaning_id')->pluck('term')->values()->all(),
            antonyms: $model->antonyms->whereNull('dictionary_meaning_id')->pluck('term')->values()->all(),
        );

        $client = $domain->toClientPayload();
        $examples = [];
        foreach ($client['meanings'] as $meaning) {
            $meaningExamples = DictionaryEntry::stringList($meaning['examples'] ?? []);
            if ($meaningExamples === [] && is_string($meaning['example'] ?? null) && trim($meaning['example']) !== '') {
                $meaningExamples = [trim($meaning['example'])];
            }

            foreach (array_slice($meaningExamples, 1) as $example) {
                $examples[] = [
                    'example' => $example,
                    'definition_ref' => $meaning['definition'] ?? null,
                ];
            }
        }

        return [
            'word' => $client['word'],
            'phonetic' => $client['phonetic'],
            'meanings' => $client['meanings'],
            'examples' => $examples,
        ];
    }
}
