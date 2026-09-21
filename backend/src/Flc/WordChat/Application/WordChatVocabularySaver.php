<?php

namespace Flc\WordChat\Application;

use Flc\Shared\Application\CommandBus;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Vocabulary\Domain\UserVocabulary;
use Flc\WordChat\Application\WordChatSaveVocabRequest;
use Throwable;

final class WordChatVocabularySaver
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly WordChatMessageRepository $messages,
        private readonly WordChatExampleExtractor $exampleExtractor,
    ) {}

    /**
     * @param  list<string>  $insightWords
     * @return array<string, mixed>|null
     */
    public function maybeSave(
        int $userId,
        string $userQuestion,
        ?WordChatSaveVocabRequest $saveVocabFromAgent,
        array $insightWords = [],
        ?int $beforeMessageId = null,
        ?string $assistantReply = null,
    ): ?array {
        $word = $saveVocabFromAgent?->word;

        if ($word === null && ($this->userRequestsSave($userQuestion) || $this->userRequestsExampleUpdate($userQuestion))) {
            $word = $this->resolveSaveWord($userQuestion, $insightWords, $userId, $beforeMessageId);
        }

        if ($word === null) {
            return null;
        }

        $saveRequest = $this->resolveSaveRequest(
            saveVocabFromAgent: $saveVocabFromAgent,
            word: $word,
            userQuestion: $userQuestion,
            userId: $userId,
            beforeMessageId: $beforeMessageId,
            assistantReply: $assistantReply,
        );

        try {
            /** @var array{vocabulary: UserVocabulary, created: bool, backfilled: bool, content_updated?: bool}|null $result */
            $result = $this->commands->dispatch(new SaveUserVocabulary(
                userId: $userId,
                word: $saveRequest->word,
                phonetic: $saveRequest->phonetic,
                meanings: $saveRequest->meanings !== [] ? $saveRequest->meanings : null,
                examples: $saveRequest->examples !== [] ? $saveRequest->examples : null,
                synonyms: $saveRequest->synonyms !== [] ? $saveRequest->synonyms : null,
                antonyms: $saveRequest->antonyms !== [] ? $saveRequest->antonyms : null,
            ));

            if (! is_array($result)) {
                return null;
            }

            $payload = $result['vocabulary']->toApiArray();
            $payload['created'] = $result['created'];
            $payload['already_saved'] = ! $result['created'];
            if (($result['content_updated'] ?? false) === true || ($result['examples_updated'] ?? false) === true) {
                $payload['content_updated'] = true;
            }

            return $payload;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function userRequestsSave(string $text): bool
    {
        $lower = Text::lower(trim($text));

        if ($lower === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(save(?:\s+this|\s+it|\s+the\s+word|\s+word)?|bookmark|add(?:\s+to)?\s+(?:my\s+)?(?:vocab(?:ulary)?|list)|(?:to|for)\s+(?:my|your)\s+(?:vocab(?:ulary)?|list)|lưu(?:\s+từ|\s+lại|\s+giúp|\s+hộ|\s+cho\s+tôi)?|cho\s+vào\s+từ\s+vựng|ghi\s+nhớ(?:\s+từ)?)\b/u',
            $lower,
        ) || (bool) preg_match('/\b(lưu\s+từ\s+này|save\s+this\s+word)\b/u', $lower);
    }

    private function userRequestsExampleUpdate(string $text): bool
    {
        $lower = Text::lower(trim($text));
        if ($lower === '') {
            return false;
        }

        return (bool) preg_match(
            '/\b(update|add|save|append|put).*(example|examples|sentence|sentences|câu\s+ví\s+dụ|ví\s+dụ)|\b(example|examples|sentence|sentences|câu\s+ví\s+dụ|ví\s+dụ).*(update|add|save|vocab(?:ulary)?|từ\s+vựng)|\b(cập\s+nhật|thêm).*(ví\s+dụ|câu\s+ví\s+dụ|example|examples)|\bsave\s+these\s+examples?\b/u',
            $lower,
        );
    }

    private function resolveSaveRequest(
        ?WordChatSaveVocabRequest $saveVocabFromAgent,
        string $word,
        string $userQuestion,
        int $userId,
        ?int $beforeMessageId,
        ?string $assistantReply,
    ): WordChatSaveVocabRequest {
        $request = $saveVocabFromAgent ?? new WordChatSaveVocabRequest(word: $word);
        if ($request->word !== $word) {
            $request = new WordChatSaveVocabRequest(
                word: $word,
                phonetic: $request->phonetic,
                meanings: $request->meanings,
                examples: $request->examples,
                synonyms: $request->synonyms,
                antonyms: $request->antonyms,
            );
        }

        if ($request->hasContentUpdate() || ! $this->userRequestsExampleUpdate($userQuestion)) {
            return $request;
        }

        $examples = $this->exampleExtractor->extractFromTexts(
            $userQuestion,
            (string) $assistantReply,
            ...$this->recentAssistantTexts($userId, $beforeMessageId),
        );

        if ($examples === []) {
            return $request;
        }

        return new WordChatSaveVocabRequest(
            word: $request->word,
            phonetic: $request->phonetic,
            meanings: $request->meanings,
            examples: $examples,
            synonyms: $request->synonyms,
            antonyms: $request->antonyms,
        );
    }

    /**
     * @return list<string>
     */
    private function recentAssistantTexts(int $userId, ?int $beforeMessageId): array
    {
        $texts = [];
        foreach ($this->messages->listForUser($userId, $beforeMessageId, 12) as $message) {
            if ($message->role !== 'assistant') {
                continue;
            }

            $content = trim($message->content);
            if ($content !== '') {
                $texts[] = $content;
            }
        }

        return array_reverse($texts);
    }

    /**
     * @param  list<string>  $insightWords
     */
    private function resolveSaveWord(
        string $userQuestion,
        array $insightWords,
        int $userId,
        ?int $beforeMessageId,
    ): ?string {
        $quoted = $this->extractQuotedWord($userQuestion);
        if ($quoted !== null) {
            return $quoted;
        }

        if (preg_match('/\b(?:save|lưu(?:\s+từ)?|bookmark|add)\s+(?:the\s+word\s+)?([a-z][a-z\'-]{1,48})\b/i', $userQuestion, $matches) === 1) {
            return Text::lower($matches[1]);
        }

        foreach ($insightWords as $word) {
            $normalized = $this->normalizeWord($word);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        foreach ($this->recentContextWords($userId, $beforeMessageId) as $word) {
            $normalized = $this->normalizeWord($word);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $this->extractLookupWord($userQuestion);
    }

    /**
     * @return list<string>
     */
    private function recentContextWords(int $userId, ?int $beforeMessageId): array
    {
        $messages = $this->messages->listForUser($userId, $beforeMessageId, 12);
        $words = [];

        foreach (array_reverse($messages) as $message) {
            $word = $this->extractLookupWord($message->content);
            if ($word !== null) {
                $words[] = $word;
            }
        }

        return $words;
    }

    private function normalizeWord(?string $word): ?string
    {
        $word = Text::lower(trim((string) $word));

        return $word !== '' ? $word : null;
    }

    private function extractQuotedWord(string $text): ?string
    {
        if (preg_match('/["\']([a-z][a-z\'-]{1,48})["\']/i', $text, $matches) === 1) {
            return Text::lower($matches[1]);
        }

        if (preg_match('/\bto\s+["\']([a-z][a-z\'-]{1,48})["\']\s+vocab/i', $text, $matches) === 1) {
            return Text::lower($matches[1]);
        }

        return null;
    }

    private function extractLookupWord(string $text): ?string
    {
        if (preg_match('/\b(?:what\s+does|meaning\s+of|define|explain)\s+["\']?([a-z][a-z\'-]{1,48})["\']?\b/i', $text, $matches) === 1) {
            return Text::lower($matches[1]);
        }

        $trimmed = Text::lower(trim($text));
        if ($trimmed !== '' && preg_match('/^[a-z][a-z\'-]{0,48}$/i', $trimmed)) {
            return $trimmed;
        }

        if (preg_match('/\b([a-z][a-z\'-]{2,48})\b/i', $text, $matches) === 1) {
            return Text::lower($matches[1]);
        }

        return null;
    }
}
