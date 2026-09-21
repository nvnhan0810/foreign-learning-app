<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\AdminSettings\Application\Query\GetAppSetting;
use Flc\Shared\Application\QueryBus;
use Flc\Vocabulary\Application\Query\ListUserVocabularies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(private readonly QueryBus $queries) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'vocabularies' => $this->queries->ask(new ListUserVocabularies($user->id)),
            'media_items' => $user->mediaItems()->orderBy('next_listen_at')->get(),
            'app_name' => $this->queries->ask(new GetAppSetting('app_name', 'FLC')),
            'extension_notice' => $this->queries->ask(new GetAppSetting('extension_notice', '')),
            'synced_at' => now()->toIso8601String(),
        ]);
    }
}
