<?php

namespace Flc\Notification\Application\Handler;

use Flc\AdminSettings\Application\Repository\AppSettingsRepository;
use Flc\Notification\Application\Command\SendVocabQuizReminders;
use Flc\Notification\Application\PushNotifier;
use Flc\Notification\Application\Repository\VocabQuizReminderRepository;
use Flc\Quiz\Application\Repository\QuizAttemptRepository;
use Flc\Shared\Application\Command;
use Flc\Shared\Application\CommandHandler;

final class SendVocabQuizRemindersHandler implements CommandHandler
{
    public function __construct(
        private readonly PushNotifier $notifier,
        private readonly AppSettingsRepository $settings,
        private readonly VocabQuizReminderRepository $reminders,
        private readonly QuizAttemptRepository $attempts,
    ) {}

    public function handle(Command $command): mixed
    {
        assert($command instanceof SendVocabQuizReminders);

        if (! $this->settings->getBool('vocab_quiz_push_enabled', true)) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        if (! $this->notifier->isConfigured()) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($this->reminders->listEligibleRecipients() as $recipient) {
            $attemptsToday = $this->attempts->countTodayForUser($recipient->userId);

            if (! $this->shouldNotify($command->slot, $attemptsToday)) {
                $stats['skipped']++;

                continue;
            }

            [$title, $body] = $this->messageForSlot($command->slot);

            $sentAny = false;
            foreach ($recipient->pushTokens as $token) {
                if ($this->notifier->sendToToken($token, $title, $body, [
                    'type' => 'vocab_quiz',
                    'route' => '/home/quiz/play',
                    'autostart' => '1',
                ])) {
                    $sentAny = true;
                } else {
                    $stats['failed']++;
                }
            }

            if ($sentAny) {
                $stats['sent']++;
            }
        }

        return $stats;
    }

    private function shouldNotify(string $slot, int $attemptsToday): bool
    {
        return match ($slot) {
            SendVocabQuizReminders::SLOT_MIDDAY => $attemptsToday === 0,
            SendVocabQuizReminders::SLOT_EVENING => $attemptsToday <= 1,
            default => false,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function messageForSlot(string $slot): array
    {
        $appName = $this->settings->get('app_name', 'Foreign Learner');

        return match ($slot) {
            SendVocabQuizReminders::SLOT_MIDDAY => [
                $appName,
                'Buổi sáng bạn chưa ôn từ nào. Chạm để làm quiz từ vựng.',
            ],
            SendVocabQuizReminders::SLOT_EVENING => [
                $appName,
                'Hôm nay bạn mới ôn ít từ. Chạm để làm thêm quiz tối này.',
            ],
            default => [$appName, 'Ôn từ vựng ngay nhé.'],
        };
    }
}
