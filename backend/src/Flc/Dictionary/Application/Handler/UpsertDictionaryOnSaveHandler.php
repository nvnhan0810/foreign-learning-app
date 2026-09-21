<?php

namespace Flc\Dictionary\Application\Handler;

use Flc\Dictionary\Application\Command\UpsertDictionaryOnSave;
use Flc\Dictionary\Application\FreeDictionaryGateway;
use Flc\Dictionary\Application\Repository\DictionaryEntryRepository;
use Flc\Dictionary\Domain\DictionaryEntry;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;
use Flc\Shared\Support\Text;

final class UpsertDictionaryOnSaveHandler implements CommandHandler
{
    public function __construct(
        private readonly DictionaryEntryRepository $entries,
        private readonly FreeDictionaryGateway $gateway,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof UpsertDictionaryOnSave);

        $normalized = Text::lower(trim($command->word));
        if ($normalized === '') {
            return null;
        }

        $entry = $this->entries->findByWord($normalized);
        $payload = $command->payload;

        if ($entry === null) {
            $payload ??= $this->gateway->fetch($normalized);
            if ($payload === null) {
                return null;
            }
            $entry = DictionaryEntry::createFromPayload($normalized, $payload);
            $this->entries->save($entry);

            return $entry;
        }

        $entry->recordSave($payload);
        $this->entries->save($entry);

        return $entry;
    }
}
