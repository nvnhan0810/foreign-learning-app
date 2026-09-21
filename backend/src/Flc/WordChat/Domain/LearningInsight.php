<?php

namespace Flc\WordChat\Domain;

final class LearningInsight
{
    /** @param  array<string, mixed>|null  $metadata */
    public function __construct(
        public ?int $id,
        public int $userId,
        public ?int $vocabularyId,
        public string $word,
        public string $insightType,
        public ?string $question,
        public string $content,
        public ?int $sourceMessageId = null,
        public ?array $metadata = null,
        public bool $quizEligible = true,
        public int $timesUsedInQuiz = 0,
        public ?string $createdAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'vocabulary_id' => $this->vocabularyId,
            'word' => $this->word,
            'insight_type' => $this->insightType,
            'question' => $this->question,
            'content' => $this->content,
            'source_message_id' => $this->sourceMessageId,
            'metadata' => $this->metadata,
            'quiz_eligible' => $this->quizEligible,
            'times_used_in_quiz' => $this->timesUsedInQuiz,
            'created_at' => $this->createdAt,
        ];
    }
}
