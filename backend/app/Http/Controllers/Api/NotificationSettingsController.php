<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\Notification\Application\Command\UpdateUserNotificationPreference;
use Flc\Notification\Application\Query\GetUserNotificationPreference;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $preference = $this->queries->ask(new GetUserNotificationPreference($request->user()->id));

        return response()->json([
            'vocab_quiz_push_enabled' => $preference->vocabQuizPushEnabled,
            'global_vocab_quiz_push_enabled' => $this->queries->ask(new GetAppSetting('vocab_quiz_push_enabled', true, asBool: true)),
            'reminder_schedule' => [
                'timezone' => 'Asia/Ho_Chi_Minh',
                'midday' => '11:00',
                'evening' => '20:00',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocab_quiz_push_enabled' => ['required', 'boolean'],
        ]);

        $preference = $this->commands->dispatch(new UpdateUserNotificationPreference(
            userId: $request->user()->id,
            vocabQuizPushEnabled: (bool) $data['vocab_quiz_push_enabled'],
        ));

        return response()->json([
            'vocab_quiz_push_enabled' => $preference->vocabQuizPushEnabled,
        ]);
    }
}
