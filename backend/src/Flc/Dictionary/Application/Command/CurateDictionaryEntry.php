<?php

namespace Flc\Dictionary\Application\Command;

use Flc\Shared\Application\Command;

final class CurateDictionaryEntry implements Command
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $word,
        public readonly array $data,
    ) {}
}
