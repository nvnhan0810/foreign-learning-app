<?php

namespace Flc\Notification\Domain;

final class UserNotificationPreference
{
    public function __construct(
        public int $userId,
        public bool $vocabQuizPushEnabled,
    ) {}
}
