<?php

namespace Flc\AdminSettings\Application\Handler;

use Flc\AdminSettings\Application\Query\GetAppSettings;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetAppSettingsHandler implements QueryHandler
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetAppSettings);

        return $this->settings->all();
    }
}
