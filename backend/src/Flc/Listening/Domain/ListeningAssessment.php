<?php

namespace Flc\Listening\Domain;

final class ListeningAssessment
{
    public const TYPE_QUIZ = 'quiz';

    public const TYPE_TEST = 'test';

    public const TYPE_EXAM = 'exam';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly int $mediaItemId,
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $questionCount,
        /** @var list<int> */
        public readonly array $questionIds,
        public readonly int $timeLimitMinutes,
        public readonly string $status,
        public readonly ?string $generatedAt,
    ) {}
}
