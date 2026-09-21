<?php

namespace Flc\Shared\Infrastructure;

use Flc\Shared\Application\Config;

final class LaravelConfig implements Config
{
    public function get(string $key, mixed $default = null): mixed
    {
        return config($key, $default);
    }
}
