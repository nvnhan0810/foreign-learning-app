<?php

namespace Flc\Listening\Domain;

final class ListeningQuestion
{
    public const TYPE_MCQ = 'mcq';

    public const TYPE_FILL_BLANK = 'fill_blank';

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_COMPREHENSION = 'comprehension';

    public function __construct(
        public readonly int $id,
        public readonly int $mediaItemId,
        public readonly int $order,
        public readonly string $questionType,
        public readonly string $prompt,
        /** @var list<string>|null */
        public readonly ?array $options,
        public readonly string $correctAnswer,
        public readonly ?string $explanation,
        public readonly ?int $audioStartSeconds,
        public readonly ?int $audioEndSeconds,
    ) {}
}
