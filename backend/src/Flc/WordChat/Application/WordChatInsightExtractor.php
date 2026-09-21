<?php

namespace Flc\WordChat\Application;

use Flc\Shared\Application\QueryBus;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Query\FindUserVocabularyByWord;
use Flc\WordChat\Application\WordChatSaveVocabRequest;
use Flc\WordChat\Domain\LearningInsight;

final class WordChatInsightExtractor
{
    /** @var list<string> */
    private const TYPES = ['meaning', 'usage', 'context', 'grammar', 'confirmation', 'note'];

    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    /**
     * @return array{content: string, insights: list<LearningInsight>, save_vocab: ?WordChatSaveVocabRequest}
     */
    public function extract(
        int $userId,
        string $userQuestion,
        string $assistantReply,
        ?int $sourceMessageId,
    ): array {
        [$cleanContent, $parsedItems, $saveVocab] = $this->parseJsonBlock($assistantReply);
        $insights = [];

        foreach ($parsedItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $insight = $this->fromParsedItem($userId, $userQuestion, $item, $sourceMessageId);
            if ($insight !== null) {
                $insights[] = $insight;
            }
        }

        if ($insights === []) {
            $fallback = $this->ruleBasedInsight($userId, $userQuestion, $cleanContent, $sourceMessageId);
            if ($fallback !== null) {
                $insights[] = $fallback;
            }
        }

        return [
            'content' => $cleanContent,
            'insights' => $insights,
            'save_vocab' => $saveVocab,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: ?WordChatSaveVocabRequest}
     */
    private function parseJsonBlock(string $text): array
    {
        if (preg_match('/```json\s*(\{[\s\S]*?\})\s*```/i', $text, $matches) !== 1) {
            return [trim($text), [], null];
        }

        $decoded = json_decode($matches[1], true);
        $items = is_array($decoded['insights'] ?? null) ? $decoded['insights'] : [];
        $saveVocab = WordChatSaveVocabRequest::fromPayload($decoded['save_vocab'] ?? null);
        $clean = trim(str_replace($matches[0], '', $text));

        return [$clean !== '' ? $clean : trim($text), $items, $saveVocab];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fromParsedItem(
        int $userId,
        string $userQuestion,
        array $item,
        ?int $sourceMessageId,
    ): ?LearningInsight {
        $word = Text::lower(trim((string) ($item['word'] ?? '')));
        $content = trim((string) ($item['content'] ?? ''));
        if ($word === '' || $content === '') {
            return null;
        }

        $type = Text::lower(trim((string) ($item['type'] ?? 'note')));
        if (! in_array($type, self::TYPES, true)) {
            $type = 'note';
        }

        return $this->buildInsight(
            userId: $userId,
            word: $word,
            insightType: $type,
            question: trim($userQuestion) !== '' ? trim($userQuestion) : null,
            content: $this->truncateContent($content),
            sourceMessageId: $sourceMessageId,
        );
    }

    private function ruleBasedInsight(
        int $userId,
        string $userQuestion,
        string $assistantReply,
        ?int $sourceMessageId,
    ): ?LearningInsight {
        $word = $this->extractWord($userQuestion) ?? $this->extractWord($assistantReply);
        $content = trim($assistantReply);

        if ($word === null || strlen($content) < 40) {
            return null;
        }

        $type = $this->inferType($userQuestion);

        return $this->buildInsight(
            userId: $userId,
            word: $word,
            insightType: $type,
            question: trim($userQuestion) !== '' ? trim($userQuestion) : null,
            content: $this->truncateContent($content),
            sourceMessageId: $sourceMessageId,
            metadata: ['source' => 'rule_based'],
        );
    }

    private function buildInsight(
        int $userId,
        string $word,
        string $insightType,
        ?string $question,
        string $content,
        ?int $sourceMessageId,
        ?array $metadata = null,
    ): LearningInsight {
        $vocabulary = $this->queries->ask(new FindUserVocabularyByWord($userId, $word));

        return new LearningInsight(
            id: null,
            userId: $userId,
            vocabularyId: is_array($vocabulary) ? ($vocabulary['id'] ?? null) : null,
            word: $word,
            insightType: $insightType,
            question: $question,
            content: $content,
            sourceMessageId: $sourceMessageId,
            metadata: $metadata,
        );
    }

    private function extractWord(string $text): ?string
    {
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

    private function inferType(string $userQuestion): string
    {
        $lower = Text::lower($userQuestion);

        if (preg_match('/\b(grammar|tense|preposition|article)\b/', $lower)) {
            return 'grammar';
        }

        if (preg_match('/\b(mean|meaning|definition|define)\b/', $lower)) {
            return 'meaning';
        }

        if (preg_match('/\b(use|usage|context|sentence|example)\b/', $lower)) {
            return 'usage';
        }

        if (preg_match('/\b(correct|right|confirm|is this)\b/', $lower)) {
            return 'confirmation';
        }

        return 'note';
    }

    private function truncateContent(string $content): string
    {
        $content = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);

        if (strlen($content) <= 500) {
            return $content;
        }

        return rtrim(substr($content, 0, 497)).'...';
    }
}
