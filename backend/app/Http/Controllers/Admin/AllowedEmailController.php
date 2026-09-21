<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Flc\Identity\Application\Command\CreateAllowedEmail;
use Flc\Identity\Application\Command\DeleteAllowedEmail;
use Flc\Identity\Application\Command\UpdateAllowedEmail;
use Flc\Identity\Application\Query\GetAllowedEmail;
use Flc\Identity\Application\Query\ListAllowedEmails;
use Flc\Identity\Domain\AllowedEmail;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\PaginatedResult;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class AllowedEmailController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function index(Request $request): View
    {
        /** @var PaginatedResult<AllowedEmail> $page */
        $page = $this->queries->ask(new ListAllowedEmails);

        $entries = new LengthAwarePaginator(
            $page->items,
            $page->total,
            $page->perPage,
            $page->currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return view('admin.allowed-emails.index', [
            'entries' => $entries,
        ]);
    }

    public function create(): View
    {
        return view('admin.allowed-emails.form', [
            'entry' => new AllowedEmail(null, '', null, true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->commands->dispatch(new CreateAllowedEmail(
            pattern: $data['pattern'],
            label: $data['label'],
            isActive: $data['is_active'],
        ));

        return redirect()->route('admin.allowed-emails.index')
            ->with('success', 'Đã thêm email vào allowlist.');
    }

    public function edit(int $allowed_email): View
    {
        $entry = $this->queries->ask(new GetAllowedEmail($allowed_email));

        abort_if($entry === null, 404);

        return view('admin.allowed-emails.form', [
            'entry' => $entry,
        ]);
    }

    public function update(Request $request, int $allowed_email): RedirectResponse
    {
        $data = $this->validated($request);
        $this->commands->dispatch(new UpdateAllowedEmail(
            id: $allowed_email,
            pattern: $data['pattern'],
            label: $data['label'],
            isActive: $data['is_active'],
        ));

        return redirect()->route('admin.allowed-emails.index')
            ->with('success', 'Đã cập nhật.');
    }

    public function destroy(int $allowed_email): RedirectResponse
    {
        $this->commands->dispatch(new DeleteAllowedEmail($allowed_email));

        return redirect()->route('admin.allowed-emails.index')
            ->with('success', 'Đã xóa.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'pattern' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]) + [
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
