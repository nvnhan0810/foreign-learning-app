<?php

namespace Flc\Dictionary\Application\Repository;

use Flc\Dictionary\Domain\DictionaryEntry;

interface DictionaryEntryRepository
{
    public function findByWord(string $word): ?DictionaryEntry;

    public function save(DictionaryEntry $entry): void;

    public function deleteByWord(string $word): void;
}
