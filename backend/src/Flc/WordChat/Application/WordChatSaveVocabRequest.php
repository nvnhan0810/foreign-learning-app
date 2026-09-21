<?php

namespace Flc\WordChat\Application;

use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Support\Text;

final class WordChatSaveVocabRequest
{
    /**
     * @param  list<array<string, mixed>>  $meanings
     * @param  list<string>  $examples
     * @param  list<string>  $synonyms
     * @param  list<string>  $antonyms
     */
    public function __construct(
        public readonly string $word,
        public readonly ?string $phonetic = null,
        public readonly array $meanings = [],
        public readonly array $examples = [],
        public readonly array $synonyms = [],
        public readonly array $antonyms = [],
    ) {}

    public function hasContentUpdate(): bool
    {
        return $this->phonetic !== null
            || $this->meanings !== []
            || $this->examples !== []
            || $this->synonyms !== []
            || $this->antonyms !== [];
    }

    /**
     * @param  array<string, mixed>|string|null  $payload
     */
    public static function fromPayload(mixed $payload): ?self
    {
        if (is_string($payload)) {
            $word = Text::lower(trim($payload));

            return $word !== '' ? new self(word: $word) : null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $word = Text::lower(trim((string) ($payload['word'] ?? '')));
        if ($word === '') {
            return null;
        }

        $phonetic = trim((string) ($payload['phonetic'] ?? ''));

        return new self(
            word: $word,
            phonetic: $phonetic !== '' ? $phonetic : null,
            meanings: DictionaryEntry::normalizeMeanings(is_array($payload['meanings'] ?? null) ? $payload['meanings'] : []),
            examples: self::stringList($payload['examples'] ?? []),
            synonyms: self::stringList($payload['synonyms'] ?? []),
            antonyms: self::stringList($payload['antonyms'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        return DictionaryEntry::stringList($values);
    }
}
