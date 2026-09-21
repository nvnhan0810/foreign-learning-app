<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Vocabulary;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Flc\Vocabulary\Application\Command\DeleteUserVocabulary;
use Flc\Vocabulary\Application\Query\GetUserVocabulary;
use Flc\Vocabulary\Application\Query\ListUserVocabularies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class VocabularyController extends Controller
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function index(Request $request): Response
    {
        $items = collect($this->queries->ask(new ListUserVocabularies($request->user()->id)))
            ->values()
            ->all();

        return Inertia::render('Vocab/Index', [
            'items' => $items,
        ]);
    }

    public function show(Request $request, Vocabulary $vocabulary): Response
    {
        $vocab = $this->queries->ask(new GetUserVocabulary($request->user()->id, $vocabulary->id));
        if ($vocab === null) {
            abort(403);
        }

        return Inertia::render('Vocab/Show', [
            'vocab' => $vocab,
        ]);
    }

    public function destroy(Request $request, Vocabulary $vocabulary): RedirectResponse
    {
        $deleted = $this->commands->dispatch(new DeleteUserVocabulary(
            $request->user()->id,
            $vocabulary->id,
        ));

        if (! $deleted) {
            abort(403);
        }

        return redirect()->route('user.home.vocab')
            ->with('success', 'Word deleted.');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toViewModel(array $item): object
    {
        $examples = collect($item['examples'] ?? [])->map(fn (array $example) => (object) $example);

        return (object) array_merge($item, [
            'examples' => $examples instanceof Collection ? $examples : collect($examples),
        ]);
    }
}
