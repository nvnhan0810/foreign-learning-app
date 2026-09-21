<?php

namespace Flc\Listening\Domain;

final class ListeningAttempt
{
    public function __construct(
        public readonly int $id,
        public readonly int $listeningAssessmentId,
        public readonly int $mediaItemId,
        public readonly string $type,
        public readonly int $userId,
        public readonly int $score,
        public readonly int $total,
        public readonly float $percentage,
        /** @var list<array<string, mixed>> */
        public readonly array $answers,
        public readonly ?string $startedAt,
        public readonly ?string $completedAt,
    ) {}
}
