<?php

namespace Flc\Media\Domain;

final class TimedTranscript
{
    /**
     * @param  list<array{start: float, end: float, text: string}>  $segments
     */
    public function __construct(
        public readonly string $text,
        public readonly array $segments,
    ) {}

    public function hasSegments(): bool
    {
        return $this->segments !== [];
    }
}
