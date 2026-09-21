<?php

namespace App\Console\Commands;

use Flc\Notification\Application\Command\SendVocabQuizReminders;
use Flc\Shared\Application\CommandBus;
use Illuminate\Console\Command;

class SendVocabQuizRemindersCommand extends Command
{
    protected $signature = 'flc:vocab-quiz-reminders {slot : midday|evening}';

    protected $description = 'Send FCM vocab quiz reminders (Asia/Ho_Chi_Minh schedule)';

    public function handle(CommandBus $commands): int
    {
        $slot = $this->argument('slot');

        if (! in_array($slot, [SendVocabQuizReminders::SLOT_MIDDAY, SendVocabQuizReminders::SLOT_EVENING], true)) {
            $this->error('Invalid slot. Use midday or evening.');

            return self::FAILURE;
        }

        $stats = $commands->dispatch(new SendVocabQuizReminders($slot));

        $this->info(sprintf(
            '[%s] sent=%d skipped=%d failed=%d',
            $slot,
            $stats['sent'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
