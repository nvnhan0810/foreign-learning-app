<?php

namespace Flc\Notification\Application\Command;

use Flc\Shared\Application\Command;

final class SendVocabQuizReminders implements Command
{
    public const SLOT_MIDDAY = 'midday';

    public const SLOT_EVENING = 'evening';

    public function __construct(
        public readonly string $slot,
    ) {}
}
