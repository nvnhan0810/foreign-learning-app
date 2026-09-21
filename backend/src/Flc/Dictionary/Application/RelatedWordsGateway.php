<?php

namespace Flc\Dictionary\Application;

interface RelatedWordsGateway
{
    /**
     * @return array{synonyms: list<string>, antonyms: list<string>}
     */
    public function fetch(string $normalizedWord): array;
}
