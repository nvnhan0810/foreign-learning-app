<?php

namespace Flc\Dictionary\Application;

interface SpellSuggestionGateway
{
    public function suggest(string $normalizedWord): ?string;
}
