<?php

namespace Flc\Dictionary\Infrastructure\Http;

/**
 * Outcome of a single upstream dictionary lookup.
 */
final class DictionarySourceResult
{
    private function __construct(
        private readonly bool $found,
        private readonly bool $unavailable,
        private readonly ?array $data,
        private readonly ?string $reason,
        private readonly bool $rateLimited,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function found(array $data): self
    {
        return new self(true, false, $data, null, false);
    }

    public static function notFound(): self
    {
        return new self(false, false, null, null, false);
    }

    public static function unavailable(string $reason, bool $rateLimited = false): self
    {
        return new self(false, true, null, $reason, $rateLimited);
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    public function isUnavailable(): bool
    {
        return $this->unavailable;
    }

    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        return $this->data;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
