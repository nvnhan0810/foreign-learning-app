<?php

namespace Flc\Notification\Application\Repository;

use Flc\Notification\Domain\VocabQuizReminderRecipient;

interface VocabQuizReminderRepository
{
    /** @return list<VocabQuizReminderRecipient> */
    public function listEligibleRecipients(): array;
}
