<?php

namespace Flc\Media\Infrastructure\Content;

use Flc\Listening\Application\Repository\ListeningAssessmentRepository;
use Flc\Listening\Domain\ListeningAssessment;
use Flc\Listening\Domain\ListeningQuestion;
use Flc\Media\Application\ListeningAssessmentGenerator;
use Flc\Media\Application\MediaContentResolver;
use Flc\Media\Application\Repository\MediaItemRepository;
use Flc\Media\Domain\MediaItem;
use Flc\Media\Infrastructure\External\CursorAgentService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class DefaultListeningAssessmentGenerator implements ListeningAssessmentGenerator
{
    public function __construct(
        private readonly CursorAgentService $cursor,
        private readonly MediaItemRepository $mediaItems,
        private readonly ListeningAssessmentRepository $assessments,
    ) {}

    public function generateQuestionBank(int $mediaItemId): void
    {
        $mediaItem = $this->mediaItems->find($mediaItemId);

        if ($mediaItem === null) {
            throw new RuntimeException('Media item not found.');
        }

        $bankSize = (int) config('listening.question_bank_count', 30);

        try {
            $content = $this->getSourceContent($mediaItem);

            DB::transaction(function () use ($mediaItem, $bankSize, $content) {
                $this->mediaItems->markQuestionBankGenerating($mediaItem->id);

                $questions = $this->buildQuestions(
                    $content,
                    $mediaItem->title,
                    ListeningAssessment::TYPE_QUIZ,
                    $bankSize,
                    $mediaItem->analysisPayload ?? []
                );

                $this->assessments->replaceQuestionBank($mediaItem->id, $questions);
                $this->mediaItems->markQuestionBankReady($mediaItem->id, count($questions));
            });
        } catch (Throwable $e) {
            $this->mediaItems->markQuestionBankFailed($mediaItem->id);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function buildQuestions(
        string $content,
        string $title,
        string $type,
        int $questionCount,
        array $analysis
    ): array {
        if ($this->cursor->isConfigured()) {
            $questions = $this->generateWithCursor($content, $title, $type, $questionCount, $analysis);

            if ($questions !== []) {
                return $questions;
            }
        }

        return $this->generateLocally($content, $title, $type, $questionCount, $analysis);
    }

    private function getSourceContent(MediaItem $mediaItem): string
    {
        $fromPayload = $mediaItem->analysisPayload['source_content'] ?? null;

        if (is_string($fromPayload) && trim($fromPayload) !== '') {
            return $fromPayload;
        }

        if ($mediaItem->transcript) {
            return $mediaItem->transcript;
        }

        throw new RuntimeException('Media item has no analyzed content. Run analysis first.');
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function generateWithCursor(
        string $content,
        string $title,
        string $type,
        int $questionCount,
        array $analysis
    ): array {
        $excerpt = mb_substr($content, 0, 12000);
        $analysisJson = json_encode($analysis);
        $contentSource = $analysis['content_source'] ?? MediaContentResolver::SOURCE_TRANSCRIPT;
        $sourceNote = match ($contentSource) {
            MediaContentResolver::SOURCE_METADATA => 'No transcript available — infer listening questions from title/description and likely video topics.',
            MediaContentResolver::SOURCE_NOTES, MediaContentResolver::SOURCE_TITLE => 'Limited metadata only — create reasonable inference questions about the topic.',
            default => 'Use the transcript for accurate detail questions.',
        };

        $typeGuide = match ($type) {
            ListeningAssessment::TYPE_QUIZ => 'Quick quiz: vocabulary and main idea questions.',
            ListeningAssessment::TYPE_TEST => 'Standard test: mix of detail, inference, and vocabulary.',
            ListeningAssessment::TYPE_EXAM => 'Comprehensive exam: harder inference, detail, and summary questions.',
        };

        $prompt = <<<PROMPT
Generate {$questionCount} listening comprehension questions for language learners.

Title: {$title}
Assessment type: {$type}
Guide: {$typeGuide}
Content source note: {$sourceNote}
Analysis: {$analysisJson}

Content:
{$excerpt}

Return JSON:
{
  "questions": [
    {
      "question_type": "mcq|fill_blank|true_false|comprehension",
      "prompt": "...",
      "options": ["A","B","C","D"] or null,
      "correct_answer": "...",
      "explanation": "...",
      "audio_start_seconds": null,
      "audio_end_seconds": null
    }
  ]
}

Rules:
- Use mcq with exactly 4 options when question_type is mcq
- correct_answer must match one option exactly for mcq
- Never repeat the answer word in the question prompt (e.g. do not ask "Which word is thoughtful?" when thoughtful is the answer)
- Distractors must be plausible English words from the passage or related vocabulary — never use placeholders like random, unknown, or missing
- Vocabulary questions should test meaning in context (definition, paraphrase, or cloze), not trivial recognition
PROMPT;

        $payload = $this->cursor->completeJson($prompt);
        $rawQuestions = $payload['questions'] ?? [];

        if (! is_array($rawQuestions)) {
            return [];
        }

        return $this->normalizeQuestions(array_slice($rawQuestions, 0, $questionCount));
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function generateLocally(
        string $transcript,
        string $title,
        string $type,
        int $questionCount,
        array $analysis
    ): array {
        $sentences = $this->splitSentences($transcript);
        $vocab = array_values(array_filter(
            $analysis['key_vocabulary'] ?? [],
            fn ($entry) => is_array($entry) && trim($entry['word'] ?? '') !== ''
        ));
        $transcriptWords = $this->extractContentWords($transcript);
        $topics = array_values(array_filter($analysis['topics'] ?? [], fn ($t) => is_string($t) && trim($t) !== ''));

        $builderOrder = ['main', 'vocab', 'detail', 'true', 'fill', 'false'];

        $questions = [];
        $vocabOffset = 0;
        $sentenceOffset = 0;
        $builderIndex = 0;
        $mainTopicAdded = false;

        while (count($questions) < $questionCount && $builderIndex < $questionCount * count($builderOrder)) {
            $builderType = $builderOrder[$builderIndex % count($builderOrder)];

            if ($builderType === 'main' && $mainTopicAdded) {
                $builderIndex++;

                continue;
            }

            $candidate = match ($builderType) {
                'main' => $this->buildMainTopicQuestion($title, $topics, $analysis),
                'vocab' => $this->buildVocabularyQuestion($vocab, $transcriptWords, $vocabOffset),
                'detail' => $this->buildDetailMcqQuestion($sentences, $sentenceOffset),
                'true' => $this->buildTrueFalseQuestion($sentences, false, $sentenceOffset),
                'fill' => $this->buildFillBlankQuestion($sentences, $sentenceOffset),
                'false' => $this->buildTrueFalseQuestion($sentences, true, $sentenceOffset + 1),
                default => null,
            };

            if ($candidate !== null) {
                $questions[] = $candidate;

                if ($builderType === 'main') {
                    $mainTopicAdded = true;
                }

                if ($builderType === 'vocab') {
                    $vocabOffset++;
                }

                if (in_array($builderType, ['detail', 'true', 'fill', 'false'], true)) {
                    $sentenceOffset++;
                }
            }

            $builderIndex++;
        }

        return array_slice($questions, 0, $questionCount);
    }

    /**
     * @return array<int, string>
     */
    private function splitSentences(string $text): array
    {
        $sentences = array_values(array_filter(
            array_map('trim', preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            fn ($sentence) => mb_strlen($sentence) > 20
        ));

        if ($sentences === []) {
            $sentences = [mb_substr(trim($text), 0, 200)];
        }

        return $sentences;
    }

    /**
     * @param  array<int, string>  $topics
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>|null
     */
    private function buildMainTopicQuestion(string $title, array $topics, array $analysis): ?array
    {
        $correct = $topics[0] ?? trim($analysis['summary'] ?? '') ?: $title;
        $correct = mb_strlen($correct) > 80 ? mb_substr($correct, 0, 77).'...' : $correct;

        $distractors = $this->pickDistractorWords(
            array_merge($topics, $this->genericTopicDistractors()),
            $correct,
            3
        );

        if (count($distractors) < 3) {
            $distractors = array_slice($this->genericTopicDistractors(), 0, 3);
        }

        $options = $this->shuffleOptions(array_merge([$correct], array_slice($distractors, 0, 3)));

        return [
            'question_type' => ListeningQuestion::TYPE_COMPREHENSION,
            'prompt' => 'What is the main topic of this listening passage?',
            'options' => $options,
            'correct_answer' => $correct,
            'explanation' => 'This best matches the main idea of the passage.',
            'audio_start_seconds' => null,
            'audio_end_seconds' => null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $vocab
     * @param  array<int, string>  $transcriptWords
     * @return array<string, mixed>|null
     */
    private function buildVocabularyQuestion(array $vocab, array $transcriptWords, int $startIndex = 0): ?array
    {
        if ($vocab === []) {
            return null;
        }

        for ($i = 0; $i < count($vocab); $i++) {
            $entry = $vocab[($startIndex + $i) % count($vocab)];
            $word = trim($entry['word'] ?? '');
            $definition = trim($entry['definition'] ?? '');

            if ($word === '' || $definition === '') {
                continue;
            }

            $distractors = $this->pickDistractorWords(
                array_merge(array_column($vocab, 'word'), $transcriptWords),
                $word,
                3
            );

            if (count($distractors) < 3) {
                continue;
            }

            $options = $this->shuffleOptions(array_merge([$word], $distractors));

            return [
                'question_type' => ListeningQuestion::TYPE_MCQ,
                'prompt' => "Which word from the listening best matches this meaning: \"{$definition}\"?",
                'options' => $options,
                'correct_answer' => $word,
                'explanation' => "\"{$word}\" — {$definition}",
                'audio_start_seconds' => null,
                'audio_end_seconds' => null,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $sentences
     * @return array<string, mixed>|null
     */
    private function buildDetailMcqQuestion(array $sentences, int $offset = 0): ?array
    {
        if (count($sentences) < 2) {
            return null;
        }

        $correctIndex = $offset % count($sentences);
        $correct = $this->truncateStatement($sentences[$correctIndex]);

        $wrongPool = [];
        foreach ($sentences as $index => $sentence) {
            if ($index !== $correctIndex) {
                $wrongPool[] = $this->truncateStatement($sentence);
            }
        }

        $distractors = $this->pickDistractorWords($wrongPool, $correct, min(3, count($wrongPool)));

        if (count($distractors) < 3) {
            return null;
        }

        $options = $this->shuffleOptions(array_merge([$correct], $distractors));

        return [
            'question_type' => ListeningQuestion::TYPE_MCQ,
            'prompt' => 'Which statement is mentioned in the listening passage?',
            'options' => $options,
            'correct_answer' => $correct,
            'explanation' => 'This statement appears in the passage.',
            'audio_start_seconds' => null,
            'audio_end_seconds' => null,
        ];
    }

    /**
     * @param  array<int, string>  $sentences
     * @return array<string, mixed>|null
     */
    private function buildTrueFalseQuestion(array $sentences, bool $makeFalse, int $offset = 0): ?array
    {
        if ($sentences === []) {
            return null;
        }

        $sentence = $sentences[$offset % count($sentences)];

        if ($makeFalse && count($sentences) > 1) {
            $other = $sentences[($offset + 1) % count($sentences)];

            $statement = $this->truncateStatement($other);

            return [
                'question_type' => ListeningQuestion::TYPE_TRUE_FALSE,
                'prompt' => 'True or false: "'.$statement.'"',
                'options' => ['True', 'False'],
                'correct_answer' => 'False',
                'explanation' => 'This statement is not supported by the passage.',
                'audio_start_seconds' => null,
                'audio_end_seconds' => null,
            ];
        }

        return [
            'question_type' => ListeningQuestion::TYPE_TRUE_FALSE,
            'prompt' => 'True or false: "'.$this->truncateStatement($sentence).'"',
            'options' => ['True', 'False'],
            'correct_answer' => 'True',
            'explanation' => 'This statement comes directly from the transcript.',
            'audio_start_seconds' => null,
            'audio_end_seconds' => null,
        ];
    }

    /**
     * @param  array<int, string>  $sentences
     * @return array<string, mixed>|null
     */
    private function buildFillBlankQuestion(array $sentences, int $offset = 0): ?array
    {
        if ($sentences === []) {
            return null;
        }

        for ($i = 0; $i < count($sentences); $i++) {
            $sentence = $sentences[($offset + $i) % count($sentences)];
            if (! preg_match_all('/\b([a-zA-Z\'-]{5,})\b/u', $sentence, $matches)) {
                continue;
            }

            $candidates = array_values(array_filter(
                $matches[1],
                fn ($word) => ! $this->isStopWord($word)
            ));

            if ($candidates === []) {
                continue;
            }

            $target = $candidates[0];
            $blanked = preg_replace('/\b'.preg_quote($target, '/').'\b/i', '______', $sentence, 1);

            if (! is_string($blanked) || ! str_contains($blanked, '______')) {
                continue;
            }

            return [
                'question_type' => ListeningQuestion::TYPE_FILL_BLANK,
                'prompt' => 'Fill in the blank: "'.$this->truncateStatement($blanked).'"',
                'options' => null,
                'correct_answer' => $target,
                'explanation' => "The missing word is \"{$target}\".",
                'audio_start_seconds' => null,
                'audio_end_seconds' => null,
            ];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function genericTopicDistractors(): array
    {
        return [
            'Daily weather report',
            'Sports highlights',
            'Technology product review',
            'Travel vlog',
            'Cooking tutorial',
            'Financial news',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractContentWords(string $text): array
    {
        if (! preg_match_all('/\b([a-zA-Z\'-]{4,})\b/u', $text, $matches)) {
            return [];
        }

        return array_values(array_filter(
            $matches[1],
            fn ($word) => ! $this->isStopWord($word)
        ));
    }

    /**
     * @param  array<int, string>  $pool
     * @return array<int, string>
     */
    private function pickDistractorWords(array $pool, string $exclude, int $count): array
    {
        $excludeLower = mb_strtolower(trim($exclude));
        $candidates = [];

        foreach ($pool as $word) {
            $word = trim((string) $word);
            $lower = mb_strtolower($word);

            if ($word === '' || $lower === $excludeLower || mb_strlen($word) < 4) {
                continue;
            }

            if ($this->isStopWord($word)) {
                continue;
            }

            if (! preg_match('/^[a-zA-Z\'-]+$/u', $word)) {
                continue;
            }

            $candidates[$lower] = $word;
        }

        $candidates = array_values($candidates);
        shuffle($candidates);

        return array_slice($candidates, 0, $count);
    }

    /**
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function shuffleOptions(array $options): array
    {
        $unique = [];

        foreach ($options as $option) {
            $option = trim((string) $option);

            if ($option !== '') {
                $unique[mb_strtolower($option)] = $option;
            }
        }

        $options = array_values($unique);
        shuffle($options);

        return $options;
    }

    private function truncateStatement(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($text) > 100 ? mb_substr($text, 0, 97).'...' : $text;
    }

    private function isStopWord(string $word): bool
    {
        static $stopWords = [
            'about', 'after', 'again', 'also', 'been', 'before', 'being', 'between',
            'could', 'does', 'doing', 'during', 'each', 'from', 'have', 'into',
            'just', 'more', 'most', 'other', 'over', 'same', 'should', 'some',
            'such', 'than', 'that', 'their', 'them', 'then', 'there', 'these',
            'they', 'this', 'those', 'through', 'under', 'very', 'were', 'what',
            'when', 'where', 'which', 'while', 'with', 'would', 'your',
            'random', 'unknown', 'missing',
        ];

        return in_array(mb_strtolower($word), $stopWords, true);
    }

    /**
     * @param  array<int, mixed>  $rawQuestions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeQuestions(array $rawQuestions): array
    {
        $normalized = [];

        foreach ($rawQuestions as $raw) {
            if (! is_array($raw) || empty($raw['prompt']) || empty($raw['correct_answer'])) {
                continue;
            }

            $type = $raw['question_type'] ?? ListeningQuestion::TYPE_MCQ;
            $options = $raw['options'] ?? null;

            if ($type === ListeningQuestion::TYPE_MCQ && is_array($options) && count($options) < 4) {
                continue;
            }

            $normalized[] = [
                'question_type' => $type,
                'prompt' => $raw['prompt'],
                'options' => $options,
                'correct_answer' => $raw['correct_answer'],
                'explanation' => $raw['explanation'] ?? null,
                'audio_start_seconds' => $raw['audio_start_seconds'] ?? null,
                'audio_end_seconds' => $raw['audio_end_seconds'] ?? null,
            ];
        }

        return $normalized;
    }
}
