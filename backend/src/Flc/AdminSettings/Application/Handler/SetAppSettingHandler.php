<?php

namespace Flc\AdminSettings\Application\Handler;

use Flc\AdminSettings\Application\Command\SetAppSetting;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class SetAppSettingHandler implements CommandHandler
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SetAppSetting);

        $this->settings->set($command->key, $command->value);

        return null;
    }
}
