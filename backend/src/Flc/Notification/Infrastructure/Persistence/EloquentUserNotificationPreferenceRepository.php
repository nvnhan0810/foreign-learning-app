<?php

namespace Flc\Notification\Infrastructure\Persistence;

use App\Models\UserNotificationPreference as UserNotificationPreferenceModel;
use Flc\Notification\Application\Repository\UserNotificationPreferenceRepository;
use Flc\Notification\Domain\UserNotificationPreference;

final class EloquentUserNotificationPreferenceRepository implements UserNotificationPreferenceRepository
{
    public function findForUser(int $userId): ?UserNotificationPreference
    {
        $model = UserNotificationPreferenceModel::query()->find($userId);

        return $model ? $this->toDomain($model) : null;
    }

    public function ensureForUser(int $userId): UserNotificationPreference
    {
        $model = UserNotificationPreferenceModel::query()->firstOrCreate(
            ['user_id' => $userId],
            ['vocab_quiz_push_enabled' => true]
        );

        return $this->toDomain($model);
    }

    public function save(UserNotificationPreference $preference): UserNotificationPreference
    {
        $model = UserNotificationPreferenceModel::query()->updateOrCreate(
            ['user_id' => $preference->userId],
            ['vocab_quiz_push_enabled' => $preference->vocabQuizPushEnabled]
        );

        return $this->toDomain($model);
    }

    private function toDomain(UserNotificationPreferenceModel $model): UserNotificationPreference
    {
        return new UserNotificationPreference(
            userId: (int) $model->user_id,
            vocabQuizPushEnabled: (bool) $model->vocab_quiz_push_enabled,
        );
    }
}
