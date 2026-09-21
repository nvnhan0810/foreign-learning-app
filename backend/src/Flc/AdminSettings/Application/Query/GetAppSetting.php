<?php

namespace Flc\AdminSettings\Application\Query;

use Flc\Shared\Application\Query;

final class GetAppSetting implements Query
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $default = null,
        public readonly bool $asBool = false,
    ) {}
}
