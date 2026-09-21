<?php

namespace Flc\WordChat\Application;

use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Support\Text;

final class WordChatPromptBuilder
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You are FLC Word Chat, an English tutor helping Vietnamese learners through English-English explanations.

Rules:
- Explain clearly with short examples when useful.
- Prefer the FLC dictionary context below when provided.
- Keep replies focused and conversational.

Personal vocabulary (bookmarking words for the user):
- When the user wants to save, bookmark, update, or add a word to THEIR personal vocabulary, confirm briefly in your reply.
- FLC saves personal vocabulary server-side from your JSON block — include save_vocab with the full entry payload when the user wants the word kept or updated.
- Use the same save_vocab shape as the legacy FLC agent skill: word, phonetic, meanings (per sense), entry-level synonyms/antonyms, and examples inside each meaning.
- Top-level save_vocab.examples is allowed only as a shorthand when the user asks to add example sentences without resending full meanings.
- NEVER say you cannot save words from chat, NEVER tell the user to tap Save in the app, and NEVER mention the global FLC dictionary in a save reply.
- Personal vocabulary saves are NOT the same as editing the global FLC dictionary.
- Example sentences and meanings in save_vocab update the user's vocabulary page. Insights alone do not update that page.

Global FLC dictionary (admin curation only):
- Only mention curating the global dictionary when the user explicitly asks to edit or curate FLC's shared dictionary entries.

After your reply, append a fenced JSON block when insights or vocabulary save apply:

```json
{"insights":[{"word":"penal","type":"usage","content":"Short summary for a quiz prompt"}],"save_vocab":{"word":"penal","phonetic":"/ˈpiːnəl/","meanings":[{"part_of_speech":"adjective","definition":"Relating to punishment by law","examples":["A penal offence can lead to imprisonment.","Penal laws set out crimes and penalties."],"synonyms":["punitive","disciplinary"],"antonyms":[]}],"synonyms":[],"antonyms":[]}}
```

save_vocab fields:
- word (required)
- phonetic (optional)
- meanings[] (optional but preferred on save/update): each item may include part_of_speech, definition, examples[], synonyms[], antonyms[]
- synonyms[] / antonyms[] (optional entry-level lists)
- examples[] (optional shorthand only; merged into the primary meaning)

Allowed insight types: meaning, usage, context, grammar, confirmation, note.
Include save_vocab when the user clearly wants the word saved or its vocabulary entry updated; use the exact headword being discussed.
When saving or updating, copy the exact example sentences and per-meaning synonym/antonym lists into save_vocab.
Omit the JSON block when nothing is worth saving for review and no vocabulary save was requested.
PROMPT;
    }

    public function followUpRulesReminder(): string
    {
        return <<<'REMINDER'
[FLC Word Chat rules for this turn]
- Personal vocabulary: when the user wants to save/bookmark/update a word in THEIR list, confirm briefly and include full save_vocab JSON (word, phonetic, meanings with examples/synonyms/antonyms per sense, plus entry-level synonyms/antonyms when useful).
- Shorthand only: if the user only asks to add example sentences, you may send save_vocab.examples, but prefer full meanings when you already explained the word.
- NEVER say you cannot save words, never say "tap Save in the app", and never mention the global FLC dictionary unless the user explicitly asks to curate it.
- Saving to personal vocabulary is NOT the same as editing the global dictionary.

REMINDER;
    }

    /**
     * @return array{word: string, phonetic: ?string, audio_url: ?string}|null
     */
    public function resolveDictionaryForMessage(string $userText): ?array
    {
        $word = $this->extractLookupWord($userText);
        if ($word === null) {
            return null;
        }

        $resolved = $this->queries->ask(new ResolveLookupWord($word));
        $dictionary = is_array($resolved['dictionary'] ?? null)
            ? $resolved['dictionary']
            : $this->queries->ask(new LookupWord($word));

        if (! is_array($dictionary)) {
            return null;
        }

        $resolvedWord = trim((string) ($dictionary['word'] ?? $word));
        $phonetic = trim((string) ($dictionary['phonetic'] ?? ''));
        $audioUrl = trim((string) ($dictionary['audio_url'] ?? ''));

        return [
            'word' => $resolvedWord !== '' ? $resolvedWord : $word,
            'phonetic' => $phonetic !== '' ? $phonetic : null,
            'audio_url' => $audioUrl !== '' ? $audioUrl : null,
        ];
    }

    public function buildUserPrompt(string $userText): string
    {
        $userText = trim($userText);
        $context = $this->dictionaryContext($userText);

        if ($context === null) {
            return $userText;
        }

        return $userText."\n\n---\nFLC dictionary context (JSON):\n".$context;
    }

    public function buildFollowUpPrompt(string $userText): string
    {
        return $this->followUpRulesReminder().$this->buildUserPrompt($userText);
    }

    public function buildInitialAgentPrompt(string $userText): string
    {
        return $this->systemPrompt()."\n\n---\n\n".$this->buildUserPrompt($userText);
    }

    public function buildWarmupPrompt(): string
    {
        return $this->systemPrompt()."\n\n---\n\nWait for the user's first message about English words, phrases, usage, or grammar.";
    }

    private function dictionaryContext(string $userText): ?string
    {
        $word = $this->extractLookupWord($userText);
        if ($word === null) {
            return null;
        }

        $resolved = $this->queries->ask(new ResolveLookupWord($word));
        $dictionary = is_array($resolved['dictionary'] ?? null)
            ? $resolved['dictionary']
            : $this->queries->ask(new LookupWord($word));

        if (! is_array($dictionary)) {
            return null;
        }

        $payload = [
            'word' => $dictionary['word'] ?? $word,
            'phonetic' => $dictionary['phonetic'] ?? null,
            'meanings' => array_slice($dictionary['meanings'] ?? [], 0, 4),
            'synonyms' => array_slice($dictionary['synonyms'] ?? [], 0, 8),
            'antonyms' => array_slice($dictionary['antonyms'] ?? [], 0, 8),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $json;
    }

    private function extractLookupWord(string $text): ?string
    {
        if (preg_match('/\b(?:what\s+does|meaning\s+of|define|explain)\s+["\']?([a-z][a-z\'-]{1,48})["\']?\b/i', $text, $matches)) {
            return Text::lower($matches[1]);
        }

        if (preg_match('/["\']([a-z][a-z\'-]{1,48})["\']/i', $text, $matches)) {
            return Text::lower($matches[1]);
        }

        $trimmed = Text::lower(trim($text));
        if ($trimmed !== '' && preg_match('/^[a-z][a-z\'-]{0,48}$/i', $trimmed)) {
            return $trimmed;
        }

        if (preg_match('/\b([a-z][a-z\'-]{2,48})\b/i', $text, $matches)) {
            return Text::lower($matches[1]);
        }

        return null;
    }
}
