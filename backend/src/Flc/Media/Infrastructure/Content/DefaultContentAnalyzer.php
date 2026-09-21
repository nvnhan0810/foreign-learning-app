<?php

namespace Flc\Media\Infrastructure\Content;

use Flc\Media\Application\ContentAnalyzer;
use Flc\Media\Application\MediaContentResolver;
use Flc\Media\Infrastructure\External\CursorAgentService;

final class DefaultContentAnalyzer implements ContentAnalyzer
{
    public function __construct(private readonly CursorAgentService $cursor) {}

    public function analyze(
        string $content,
        string $title,
        string $language = 'en',
        string $contentSource = MediaContentResolver::SOURCE_TRANSCRIPT,
    ): array {
        if ($this->cursor->isConfigured()) {
            $analysis = $this->analyzeWithCursor($content, $title, $language, $contentSource);

            if ($analysis) {
                return $analysis;
            }
        }

        return $this->analyzeLocally($content, $title, $language, $contentSource);
    }

    private function analyzeWithCursor(
        string $content,
        string $title,
        string $language,
        string $contentSource,
    ): ?array {
        $excerpt = mb_substr($content, 0, 12000);
        $sourceNote = $this->sourceNote($contentSource);

        $prompt = <<<PROMPT
Analyze this listening content for language learners.

Title: {$title}
Language: {$language}
Content source: {$contentSource}
{$sourceNote}

Content:
{$excerpt}

Return JSON with keys:
- summary (string)
- topics (string array)
- key_vocabulary (array of 5-12 objects: word, definition, optional part_of_speech, optional example — topic-specific words learners should review)
- difficulty (beginner|intermediate|advanced)
- main_ideas (string array)
PROMPT;

        $payload = $this->cursor->completeJson($prompt);

        if (! is_array($payload)) {
            return null;
        }

        return [
            'summary' => $payload['summary'] ?? '',
            'topics' => $payload['topics'] ?? [],
            'key_vocabulary' => $payload['key_vocabulary'] ?? [],
            'difficulty' => $payload['difficulty'] ?? 'intermediate',
            'main_ideas' => $payload['main_ideas'] ?? [],
            'source' => 'cursor',
            'content_source' => $contentSource,
        ];
    }

    private function analyzeLocally(
        string $content,
        string $title,
        string $language,
        string $contentSource,
    ): array {
        $words = str_word_count(strtolower($content), 1);
        $uniqueWords = array_unique($words);
        $wordCount = count($words);

        $sentences = preg_split('/[.!?]+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $summary = trim($sentences[0] ?? mb_substr($content, 0, 200));

        if ($contentSource !== MediaContentResolver::SOURCE_TRANSCRIPT) {
            $summary = $summary !== '' ? $summary : "Listening practice based on: {$title}";
        }

        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'to', 'of', 'and', 'in', 'on', 'for', 'it', 'that', 'this'];
        $freq = array_count_values(array_filter($uniqueWords, fn ($w) => strlen($w) > 4 && ! in_array($w, $stopWords, true)));
        arsort($freq);
        $topWords = array_slice(array_keys($freq), 0, 8);

        return [
            'summary' => $summary,
            'topics' => [$title],
            'key_vocabulary' => array_map(fn ($w) => ['word' => $w, 'definition' => ''], $topWords),
            'difficulty' => $wordCount > 800 ? 'advanced' : ($wordCount > 300 ? 'intermediate' : 'beginner'),
            'main_ideas' => array_slice(array_map('trim', $sentences), 0, 3),
            'word_count' => $wordCount,
            'source' => 'local',
            'language' => $language,
            'content_source' => $contentSource,
        ];
    }

    private function sourceNote(string $contentSource): string
    {
        return match ($contentSource) {
            MediaContentResolver::SOURCE_METADATA => 'No transcript/captions were available. Use the video title and description to infer likely topics and vocabulary.',
            MediaContentResolver::SOURCE_NOTES, MediaContentResolver::SOURCE_TITLE => 'Only limited metadata is available. Infer reasonable listening topics and vocabulary from the title/notes.',
            default => 'Full transcript is available.',
        };
    }
}
