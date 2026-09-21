<?php

namespace Flc\Dictionary\Application\Command;

use Flc\Shared\Application\Command;

final class UpsertDictionaryOnSave implements Command
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public readonly string $word,
        public readonly ?array $payload = null,
    ) {}
}
