<?php

namespace Flc\Dictionary\Application\Handler;

use Flc\Dictionary\Application\Command\CurateDictionaryEntry;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Support\Text;

final class CurateDictionaryEntryHandler implements CommandHandler
{
    public function __construct(
        private readonly DictionaryEntryRepository $entries,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof CurateDictionaryEntry);

        $normalized = Text::lower(trim($command->word));
        $entry = $this->entries->findByWord($normalized);

        if ($entry === null) {
            $entry = DictionaryEntry::createFromPayload($normalized, [
                'phonetic' => $command->data['phonetic'] ?? null,
                'audio_url' => $command->data['audio_url'] ?? null,
                'source' => 'admin',
                'meanings' => [],
                'synonyms' => [],
                'antonyms' => [],
            ]);
        }

        $entry->curate(array_merge($command->data, ['word' => $normalized]));
        $this->entries->save($entry);

        return $entry;
    }
}
