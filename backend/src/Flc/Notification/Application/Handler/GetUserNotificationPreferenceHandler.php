<?php

namespace Flc\Notification\Application\Handler;

use Flc\Notification\Application\Query\GetUserNotificationPreference;
use Flc\Notification\Application\Repository\UserNotificationPreferenceRepository;
use Flc\Shared\Application\Query;
use Flc\Shared\Application\QueryHandler;

final class GetUserNotificationPreferenceHandler implements QueryHandler
{
    public function __construct(
        private readonly UserNotificationPreferenceRepository $preferences,
    ) {}

    public function handle(Query $query): mixed
    {
        assert($query instanceof GetUserNotificationPreference);

        return $this->preferences->ensureForUser($query->userId);
    }
}
