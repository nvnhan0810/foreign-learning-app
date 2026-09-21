<?php

namespace Flc\AdminSettings\Application\Handler;

use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetAppSettingHandler implements QueryHandler
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetAppSetting);

        if ($query->asBool) {
            $default = is_bool($query->default) ? $query->default : false;

            return $this->settings->getBool($query->key, $default);
        }

        return $this->settings->get($query->key, $query->default);
    }
}
