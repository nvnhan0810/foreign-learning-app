<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListeningAssessment;
use App\Models\MediaItem;
use App\Models\User;
use App\Models\Vocabulary;
use Flc\Identity\Application\Query\CountActiveAllowedEmails;
use Flc\Shared\Application\QueryBus;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly QueryBus $queries) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'users' => User::query()->count(),
                'vocabularies' => Vocabulary::query()->count(),
                'media_items' => MediaItem::query()->count(),
                'listening_assessments' => ListeningAssessment::query()->count(),
                'allowlist' => $this->queries->ask(new CountActiveAllowedEmails),
            ],
        ]);
    }
}
