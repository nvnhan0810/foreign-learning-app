<?php

namespace Flc\Dictionary\Infrastructure\Http;

interface DictionaryHttpSource
{
    public function id(): string;

    public function fetch(string $normalizedWord): DictionarySourceResult;
}
