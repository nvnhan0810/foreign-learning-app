<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Flc\AdminSettings\Application\Command\SetAppSettings;
use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'allow_all_emails' => $this->queries->ask(new GetAppSetting('allow_all_emails', false, asBool: true)),
            'extension_notice' => $this->queries->ask(new GetAppSetting('extension_notice', '')),
            'app_name' => $this->queries->ask(new GetAppSetting('app_name', 'FLC')),
            'vocab_quiz_push_enabled' => $this->queries->ask(new GetAppSetting('vocab_quiz_push_enabled', true, asBool: true)),
            'env_allowlist' => config('flc.allowed_emails', []),
            'env_allow_all' => config('flc.allow_all_emails', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'allow_all_emails' => ['sometimes', 'boolean'],
            'extension_notice' => ['nullable', 'string', 'max:5000'],
            'app_name' => ['nullable', 'string', 'max:120'],
            'vocab_quiz_push_enabled' => ['sometimes', 'boolean'],
        ]);

        $this->commands->dispatch(new SetAppSettings([
            'allow_all_emails' => $request->boolean('allow_all_emails'),
            'extension_notice' => $data['extension_notice'] ?? '',
            'app_name' => $data['app_name'] ?? 'FLC',
            'vocab_quiz_push_enabled' => $request->boolean('vocab_quiz_push_enabled'),
        ]));

        return redirect()->route('admin.settings.edit')
            ->with('success', 'Đã lưu cài đặt.');
    }
}
