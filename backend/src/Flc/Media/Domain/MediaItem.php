<?php

namespace Flc\Media\Domain;

final class MediaItem
{
    public const TYPE_YOUTUBE = 'youtube';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_MP3 = 'mp3';

    public const ANALYSIS_PENDING = 'pending';

    public const ANALYSIS_PROCESSING = 'processing';

    public const ANALYSIS_READY = 'ready';

    public const ANALYSIS_FAILED = 'failed';

    public const QUESTION_BANK_READY = 'ready';

    public const DIFFICULTY_BEGINNER = 'beginner';

    public const DIFFICULTY_INTERMEDIATE = 'intermediate';

    public const DIFFICULTY_ADVANCED = 'advanced';

    /** @var list<string> */
    public const DIFFICULTIES = [
        self::DIFFICULTY_BEGINNER,
        self::DIFFICULTY_INTERMEDIATE,
        self::DIFFICULTY_ADVANCED,
    ];

    public function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $sourceId,
        public readonly string $type,
        public readonly string $language,
        public readonly ?string $notes,
        public readonly ?string $transcript,
        public readonly ?string $difficulty,
        public readonly string $analysisStatus,
        /** @var array<string, mixed>|null */
        public readonly ?array $analysisPayload,
        public readonly ?string $questionBankStatus,
        public readonly int $questionBankCount,
    ) {}

    public function isQuestionBankReady(): bool
    {
        return $this->questionBankStatus === self::QUESTION_BANK_READY
            && $this->questionBankCount > 0;
    }

    public static function normalizeDifficulty(?string $value): string
    {
        return in_array($value, self::DIFFICULTIES, true)
            ? $value
            : self::DIFFICULTY_INTERMEDIATE;
    }
}
