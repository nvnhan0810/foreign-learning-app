<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    public function __construct(private readonly QueryBus $queries) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'app_name' => $this->queries->ask(new GetAppSetting('app_name', 'FLC')),
            'extension_notice' => $this->queries->ask(new GetAppSetting('extension_notice', '')),
            'vocab_quiz_push_enabled' => $this->queries->ask(new GetAppSetting('vocab_quiz_push_enabled', true, asBool: true)),
            'vocab_quiz_reminder_schedule' => [
                'timezone' => 'Asia/Ho_Chi_Minh',
                'midday' => '11:00',
                'evening' => '20:00',
            ],
        ]);
    }
}
