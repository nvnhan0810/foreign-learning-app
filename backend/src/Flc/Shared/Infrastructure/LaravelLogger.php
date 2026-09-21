<?php

namespace Flc\Shared\Infrastructure;

use Flc\Shared\Application\Logger;
use Illuminate\Support\Facades\Log;

final class LaravelLogger implements Logger
{
    public function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }
}
