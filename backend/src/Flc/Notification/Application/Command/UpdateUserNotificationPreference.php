<?php

namespace Flc\Notification\Application\Command;

use Flc\Shared\Application\Command;

final class UpdateUserNotificationPreference implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly bool $vocabQuizPushEnabled,
    ) {}
}
