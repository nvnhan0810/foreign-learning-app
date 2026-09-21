<?php

namespace Flc\Identity\Domain;

final class AllowedEmail
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $pattern,
        public readonly ?string $label,
        public readonly bool $isActive,
    ) {}

    public function exists(): bool
    {
        return $this->id !== null;
    }
}
