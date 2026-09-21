<?php

namespace Flc\Notification\Application\Repository;

use Flc\Notification\Domain\UserNotificationPreference;

interface UserNotificationPreferenceRepository
{
    public function findForUser(int $userId): ?UserNotificationPreference;

    public function ensureForUser(int $userId): UserNotificationPreference;

    public function save(UserNotificationPreference $preference): UserNotificationPreference;
}
