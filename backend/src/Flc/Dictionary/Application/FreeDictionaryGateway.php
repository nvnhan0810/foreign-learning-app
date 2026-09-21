<?php

namespace Flc\Dictionary\Application;

interface FreeDictionaryGateway
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $normalizedWord): ?array;
}
