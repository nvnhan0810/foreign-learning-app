<?php

namespace Flc\Shared\Infrastructure;

use DateTimeImmutable;
use Flc\Shared\Application\Clock;

/**
 * Uses Laravel's now() so Carbon::setTestNow() works in tests.
 */
final class LaravelClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface(now());
    }
}
