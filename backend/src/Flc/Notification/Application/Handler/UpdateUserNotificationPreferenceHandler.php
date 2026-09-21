<?php

namespace Flc\Notification\Application\Handler;

use Flc\Notification\Application\Command\UpdateUserNotificationPreference;
use Flc\Notification\Application\Repository\UserNotificationPreferenceRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class UpdateUserNotificationPreferenceHandler implements CommandHandler
{
    public function __construct(
        private readonly UserNotificationPreferenceRepository $preferences,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof UpdateUserNotificationPreference);

        $preference = $this->preferences->ensureForUser($command->userId);
        $preference->vocabQuizPushEnabled = $command->vocabQuizPushEnabled;

        return $this->preferences->save($preference);
    }
}
