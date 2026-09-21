<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DictionaryEntry;
use Flc\Dictionary\Application\Command\CurateDictionaryEntry;
use Flc\Dictionary\Application\Command\DeleteDictionaryEntry;
use Flc\Dictionary\Application\DictionaryMeaningsEditor;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Support\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class DictionaryController extends Controller
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function index(Request $request): View
    {
        $query = DictionaryEntry::query()->withCount('meanings')->latest();

        if ($search = $request->string('q')->trim()->toString()) {
            $query->where('word', 'like', '%'.$search.'%');
        }

        if ($request->string('curated')->toString() === '1') {
            $query->where('is_curated', true);
        } elseif ($request->string('curated')->toString() === '0') {
            $query->where('is_curated', false);
        }

        return view('admin.dictionary.index', [
            'entries' => $query->paginate(25)->withQueryString(),
            'search' => $search ?? '',
            'curated' => $request->string('curated')->toString(),
        ]);
    }

    public function create(): View
    {
        $formMeanings = [[
            'part_of_speech' => '',
            'definition' => '',
            'examples_text' => '',
            'synonyms_text' => '',
            'antonyms_text' => '',
        ]];

        return view('admin.dictionary.form', [
            'entry' => null,
            'formMeanings' => $formMeanings,
            'meaningsJson' => old('meanings_json', DictionaryMeaningsEditor::toPrettyJson([])),
            'meaningsEditor' => old('meanings_editor', DictionaryMeaningsEditor::MODE_FORM),
            'meaningsAiPrompt' => DictionaryMeaningsEditor::aiPrompt((string) old('word', '')),
            'entrySynonymsText' => '',
            'entryAntonymsText' => '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedDictionary($request);
        $word = Text::lower(trim($data['word']));

        if (DictionaryEntry::query()->where('word', $word)->exists()) {
            return back()->withInput()->withErrors(['word' => 'Từ này đã có trong từ điển.']);
        }

        $this->commands->dispatch(new CurateDictionaryEntry($word, $data));

        $entry = DictionaryEntry::query()->where('word', $word)->firstOrFail();

        return redirect()->route('admin.dictionary.edit', $entry)
            ->with('success', 'Đã tạo từ trong từ điển.');
    }

    public function edit(DictionaryEntry $dictionary): View
    {
        $dictionary->load([
            'meanings.examples',
            'meanings.synonyms',
            'meanings.antonyms',
            'synonyms',
            'antonyms',
        ]);

        $formMeanings = $dictionary->meanings->map(function ($meaning) {
            return [
                'part_of_speech' => $meaning->part_of_speech ?? '',
                'definition' => $meaning->definition,
                'examples_text' => $meaning->examples->pluck('example')->implode("\n"),
                'synonyms_text' => $meaning->synonyms->pluck('term')->implode(', '),
                'antonyms_text' => $meaning->antonyms->pluck('term')->implode(', '),
            ];
        })->values()->all();

        if ($formMeanings === []) {
            $formMeanings = [[
                'part_of_speech' => '',
                'definition' => '',
                'examples_text' => '',
                'synonyms_text' => '',
                'antonyms_text' => '',
            ]];
        }

        return view('admin.dictionary.form', [
            'entry' => $dictionary,
            'formMeanings' => $formMeanings,
            'meaningsJson' => old(
                'meanings_json',
                DictionaryMeaningsEditor::toPrettyJson(DictionaryMeaningsEditor::fromFormRows($formMeanings))
            ),
            'meaningsEditor' => old('meanings_editor', DictionaryMeaningsEditor::MODE_FORM),
            'meaningsAiPrompt' => DictionaryMeaningsEditor::aiPrompt($dictionary->word),
            'entrySynonymsText' => $dictionary->synonyms->whereNull('dictionary_meaning_id')->pluck('term')->implode(', '),
            'entryAntonymsText' => $dictionary->antonyms->whereNull('dictionary_meaning_id')->pluck('term')->implode(', '),
        ]);
    }

    public function update(Request $request, DictionaryEntry $dictionary): RedirectResponse
    {
        $data = $this->validatedDictionary($request);
        $word = Text::lower(trim($data['word']));

        $duplicate = DictionaryEntry::query()
            ->where('word', $word)
            ->where('id', '!=', $dictionary->id)
            ->exists();

        if ($duplicate) {
            return back()->withInput()->withErrors(['word' => 'Từ này đã có trong từ điển.']);
        }

        $this->commands->dispatch(new CurateDictionaryEntry($word, $data));

        return redirect()->route('admin.dictionary.edit', $dictionary)
            ->with('success', 'Đã cập nhật từ điển.');
    }

    public function destroy(DictionaryEntry $dictionary): RedirectResponse
    {
        if ($dictionary->vocabularies()->exists()) {
            return back()->with('error', 'Không thể xóa: còn user đang lưu từ này để học.');
        }

        $this->commands->dispatch(new DeleteDictionaryEntry($dictionary->word));

        return redirect()->route('admin.dictionary.index')
            ->with('success', 'Đã xóa từ khỏi từ điển.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedDictionary(Request $request): array
    {
        $editor = $request->string('meanings_editor')->toString();
        if ($editor !== DictionaryMeaningsEditor::MODE_JSON) {
            $editor = DictionaryMeaningsEditor::MODE_FORM;
        }

        $rules = [
            'word' => ['required', 'string', 'max:255'],
            'phonetic' => ['nullable', 'string', 'max:120'],
            'audio_url' => ['nullable', 'string', 'max:2000'],
            'entry_synonyms' => ['nullable', 'string'],
            'entry_antonyms' => ['nullable', 'string'],
            'meanings_editor' => ['nullable', 'string'],
        ];

        if ($editor === DictionaryMeaningsEditor::MODE_JSON) {
            $rules['meanings_json'] = ['required', 'string'];
        } else {
            $rules['meanings'] = ['required', 'array', 'min:1'];
            $rules['meanings.*.part_of_speech'] = ['nullable', 'string', 'max:40'];
            $rules['meanings.*.definition'] = ['required', 'string'];
            $rules['meanings.*.examples_text'] = ['nullable', 'string'];
            $rules['meanings.*.synonyms_text'] = ['nullable', 'string'];
            $rules['meanings.*.antonyms_text'] = ['nullable', 'string'];
        }

        $data = $request->validate($rules);

        try {
            $meanings = $editor === DictionaryMeaningsEditor::MODE_JSON
                ? DictionaryMeaningsEditor::fromJson((string) ($data['meanings_json'] ?? ''))
                : DictionaryMeaningsEditor::fromFormRows($data['meanings'] ?? []);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'meanings_json' => $e->getMessage(),
            ]);
        }

        if ($meanings === []) {
            throw ValidationException::withMessages([
                $editor === DictionaryMeaningsEditor::MODE_JSON ? 'meanings_json' : 'meanings' => 'Cần ít nhất một nghĩa.',
            ]);
        }

        return [
            'word' => $data['word'],
            'phonetic' => $data['phonetic'] ?? null,
            'audio_url' => $data['audio_url'] ?? null,
            'meanings' => $meanings,
            'synonyms' => $this->csvFromEntryField((string) ($data['entry_synonyms'] ?? '')),
            'antonyms' => $this->csvFromEntryField((string) ($data['entry_antonyms'] ?? '')),
        ];
    }

    /**
     * @return list<string>
     */
    private function csvFromEntryField(string $text): array
    {
        $parts = preg_split('/[,;]+/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
