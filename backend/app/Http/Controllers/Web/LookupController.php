<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Flc\Shared\Support\Text;
use Flc\Vocabulary\Application\Command\SaveUserVocabulary;
use Flc\Vocabulary\Application\Query\FindUserVocabularyByWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LookupController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function index(Request $request): Response
    {
        $prefill = trim((string) $request->query('q', ''));

        return Inertia::render('Learn/Index', [
            'result' => session('lookup_result'),
            'word' => old('word', session('lookup_word', $prefill)),
            'saved' => (bool) session('lookup_saved', false),
            'savedVocabularyId' => session('lookup_saved_vocabulary_id'),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:500'],
        ]);

        $text = trim($data['word']);
        $word = Text::lower(preg_replace('/\s+/', ' ', $text) ?? $text);

        if (! preg_match('/^[a-z][a-z\s\'-]*$/i', $word)) {
            return back()->withInput()->with('error', 'Enter a valid English word or phrase.');
        }

        return $this->redirectForWord($request, $word, $text, preferDetail: false);
    }

    /**
     * Open a related word: prefer saved vocabulary detail when available, else dictionary lookup.
     */
    public function openWord(Request $request, string $word): RedirectResponse
    {
        $text = trim(urldecode($word));
        $normalized = Text::lower(preg_replace('/\s+/', ' ', $text) ?? $text);

        if ($normalized === '' || ! preg_match('/^[a-z][a-z\s\'-]*$/i', $normalized)) {
            return redirect()->route('user.home.lookup')
                ->with('error', 'Enter a valid English word or phrase.');
        }

        $preferDetail = $request->boolean('detail', true);

        return $this->redirectForWord($request, $normalized, $text, preferDetail: $preferDetail);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:120'],
            'phonetic' => ['nullable', 'string', 'max:120'],
            'meanings' => ['nullable', 'array'],
        ]);

        $word = Text::lower(trim($data['word']));
        $meanings = is_array($data['meanings'] ?? null) ? $data['meanings'] : null;

        $result = $this->commands->dispatch(new SaveUserVocabulary(
            userId: $request->user()->id,
            word: $word,
            phonetic: $data['phonetic'] ?? null,
            meanings: $meanings,
        ));

        if ($result === null) {
            return $this->redirectToLookupAfterSave($data, 'Could not save word.');
        }

        if ($result['created'] ?? false) {
            $lookup = $this->queries->ask(new LookupWord($word));

            return $this->redirectToLookupAfterSave($data, 'Word saved.', is_array($lookup) ? $lookup : null);
        }

        if ($result['backfilled'] ?? false) {
            $lookup = $this->queries->ask(new LookupWord($word));

            return $this->redirectToLookupAfterSave(
                $data,
                'Updated synonyms and antonyms for this saved word.',
                is_array($lookup) ? $lookup : null,
            );
        }

        return $this->redirectToLookupAfterSave($data, 'This word is already in your list.');
    }

    /**
     * @param  array{word: string, phonetic?: string|null, meanings?: array<int, mixed>|null}  $data
     * @param  array<string, mixed>|null  $lookup
     */
    private function redirectToLookupAfterSave(array $data, string $message, ?array $lookup = null): RedirectResponse
    {
        $word = Text::lower(trim($data['word']));
        $lookup ??= $this->queries->ask(new LookupWord($word));

        if ($lookup === null) {
            $lookup = [
                'word' => $data['word'],
                'phonetic' => $data['phonetic'] ?? null,
                'audio_url' => null,
                'meanings' => $data['meanings'] ?? [],
            ];
        }

        /** @var array<string, mixed>|null $savedVocab */
        $savedVocab = $this->queries->ask(new FindUserVocabularyByWord(
            userId: (int) request()->user()->id,
            word: $word,
        ));

        return redirect()->route('user.home.lookup')
            ->with('success', $message)
            ->with('lookup_saved', true)
            ->with('lookup_saved_vocabulary_id', is_array($savedVocab) ? ($savedVocab['id'] ?? null) : null)
            ->with('lookup_word', $data['word'])
            ->with('lookup_result', $lookup);
    }

    private function redirectForWord(
        Request $request,
        string $normalizedWord,
        string $displayWord,
        bool $preferDetail,
    ): RedirectResponse {
        /** @var array<string, mixed>|null $savedVocab */
        $savedVocab = $this->queries->ask(new FindUserVocabularyByWord(
            userId: (int) $request->user()->id,
            word: $normalizedWord,
        ));

        if ($preferDetail && is_array($savedVocab) && ! empty($savedVocab['id'])) {
            return redirect()->route('user.home.vocab.show', $savedVocab['id']);
        }

        $result = $this->queries->ask(new LookupWord($normalizedWord));

        if (! $result) {
            return redirect()->route('user.home.lookup')
                ->withInput(['word' => $displayWord])
                ->with('error', 'Word not found.');
        }

        return redirect()->route('user.home.lookup')
            ->with('lookup_word', $displayWord)
            ->with('lookup_result', $result)
            ->with('lookup_saved', is_array($savedVocab))
            ->with('lookup_saved_vocabulary_id', is_array($savedVocab) ? ($savedVocab['id'] ?? null) : null);
    }

    public function pronounce(Request $request, string $word): JsonResponse|RedirectResponse
    {
        $result = $this->queries->ask(new LookupWord($word));

        if (! $result || empty($result['audio_url'])) {
            abort(404, 'No pronunciation audio for this word.');
        }

        if ($request->expectsJson()) {
            return response()->json(['audio_url' => $result['audio_url']]);
        }

        return redirect()->away($result['audio_url']);
    }
}
