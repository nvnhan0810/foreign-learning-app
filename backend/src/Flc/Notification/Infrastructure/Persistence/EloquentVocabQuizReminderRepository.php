<?php

namespace Flc\Notification\Infrastructure\Persistence;

use App\Models\User;
use Flc\Notification\Application\Repository\VocabQuizReminderRepository;
use Flc\Notification\Domain\VocabQuizReminderRecipient;

final class EloquentVocabQuizReminderRepository implements VocabQuizReminderRepository
{
    public function listEligibleRecipients(): array
    {
        return User::query()
            ->has('vocabularies', '>=', 4)
            ->whereHas('pushTokens')
            ->with(['pushTokens', 'notificationPreference'])
            ->get()
            ->filter(function (User $user) {
                if ($user->pushTokens->isEmpty()) {
                    return false;
                }

                $pref = $user->notificationPreference;

                return $pref === null || $pref->vocab_quiz_push_enabled;
            })
            ->map(function (User $user) {
                return new VocabQuizReminderRecipient(
                    userId: (int) $user->id,
                    pushTokens: $user->pushTokens->pluck('token')->all(),
                );
            })
            ->values()
            ->all();
    }
}
