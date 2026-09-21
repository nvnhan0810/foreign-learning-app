<?php

namespace Flc\Notification\Domain;

final class VocabQuizReminderRecipient
{
    /**
     * @param  list<string>  $pushTokens
     */
    public function __construct(
        public int $userId,
        public array $pushTokens,
    ) {}
}
