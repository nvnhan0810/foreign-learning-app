<?php

namespace Flc\Notification\Application\Query;

use Flc\Shared\Application\Query;

final class GetUserNotificationPreference implements Query
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
