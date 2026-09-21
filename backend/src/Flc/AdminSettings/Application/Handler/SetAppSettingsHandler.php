<?php

namespace Flc\AdminSettings\Application\Handler;

use Flc\AdminSettings\Application\Command\SetAppSettings;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class SetAppSettingsHandler implements CommandHandler
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SetAppSettings);

        $this->settings->setMany($command->values);

        return null;
    }
}
