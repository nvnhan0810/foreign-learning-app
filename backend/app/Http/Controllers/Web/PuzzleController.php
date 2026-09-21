<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GameRecord;
use App\Models\User;
use Flc\Puzzle\Application\Query\GetNextHangmanPuzzle;
use Flc\Puzzle\Application\Query\GetNextScramblePuzzle;
use Flc\Puzzle\Application\Query\GetNextWordSearchPuzzle;
use Flc\Puzzle\Application\Query\GetNextWordlePuzzle;
use Flc\Puzzle\Application\Query\GetScrambleHint;
use Flc\Puzzle\Domain\HangmanGrader;
use Flc\Puzzle\Domain\WordSearchGrader;
use Flc\Puzzle\Domain\WordleGrader;
use Flc\Puzzle\Domain\WordleKeyboardBuilder;
use Flc\Quiz\Application\Command\RecordQuizAttempt;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Flc\Vocabulary\Application\Query\GetUserVocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PuzzleController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $mode = $request->query('mode');

        if (is_string($mode) && $mode !== '' && ! in_array($mode, ['scramble', 'wordle', 'hangman', 'word_search'], true)) {
            return redirect()
                ->route('user.home.puzzle')
                ->with('error', 'Coming soon.');
        }

        $this->clearScrambleSession();
        $this->clearWordleSession();
        $this->clearHangmanSession();
        $this->clearWordSearchSession();

        return Inertia::render('Puzzle/Index');
    }

    public function exit(Request $request): RedirectResponse
    {
        $this->clearScrambleSession();
        $this->clearWordleSession();
        $this->clearHangmanSession();
        $this->clearWordSearchSession();

        return redirect()->route('user.home.quiz');
    }

    public function scramble(Request $request): Response|RedirectResponse
    {
        if ($request->query('autostart') === '1' && ! session()->has('puzzle_scramble')) {
            return $this->nextScramble($request);
        }

        $reveal = session('puzzle_scramble_reveal');
        $startedAt = session('puzzle_scramble_started_at');
        $wordStartedAt = session('puzzle_scramble_word_started_at');
        $elapsed = session('puzzle_scramble_elapsed');

        if (session()->has('puzzle_scramble') && ! is_numeric($wordStartedAt)) {
            $wordStartedAt = now()->timestamp;
            session(['puzzle_scramble_word_started_at' => $wordStartedAt]);
        }

        /** @var User $user */
        $user = $request->user();
        $celebrateRecord = session('puzzle_scramble_celebrate_record');
        session()->forget('puzzle_scramble_celebrate_record');

        $puzzle = session('puzzle_scramble');
        if (is_array($puzzle)) {
            unset($puzzle['correct_word']);
        }

        return Inertia::render('Puzzle/Scramble', [
            'puzzle' => $puzzle,
            'hint' => session('puzzle_scramble_hint'),
            'feedback' => session('puzzle_scramble_feedback'),
            'wasCorrect' => session('puzzle_scramble_was_correct'),
            'reveal' => is_array($reveal) ? $reveal : null,
            'startedAt' => is_numeric($startedAt) ? (int) $startedAt : null,
            'wordStartedAt' => is_numeric($wordStartedAt) ? (int) $wordStartedAt : null,
            'elapsedSeconds' => is_numeric($elapsed) ? (int) $elapsed : null,
            'sessionCorrect' => (int) session('puzzle_scramble_session_correct', 0),
            'bestCorrect' => GameRecord::bestCorrectFor($user->id, GameRecord::GAME_SCRAMBLE),
            'celebrateRecord' => $celebrateRecord,
        ]);
    }

    public function nextScramble(Request $request): RedirectResponse
    {
        $puzzle = $this->queries->ask(new GetNextScramblePuzzle($request->user()->id));

        if (! $puzzle) {
            $this->clearScrambleSession();

            return redirect()
                ->route('user.home.puzzle.scramble')
                ->with('error', 'You need at least one saved single word (3–14 letters) to play Scramble. Add words in Vocabulary.');
        }

        $payload = [
            'puzzle_scramble' => $puzzle,
            'puzzle_scramble_hint' => null,
            'puzzle_scramble_feedback' => null,
            'puzzle_scramble_was_correct' => null,
            'puzzle_scramble_reveal' => null,
            'puzzle_scramble_elapsed' => null,
            'puzzle_scramble_word_started_at' => now()->timestamp,
            'puzzle_scramble_celebrate_record' => null,
        ];

        // Keep one continuous session clock + score across rounds until the player exits.
        if (! session()->has('puzzle_scramble_started_at')) {
            $payload['puzzle_scramble_started_at'] = now()->timestamp;
            $payload['puzzle_scramble_session_correct'] = 0;
        }

        session($payload);

        return redirect()->route('user.home.puzzle.scramble');
    }

    public function hintScramble(Request $request): RedirectResponse
    {
        $puzzle = session('puzzle_scramble');

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.scramble');
        }

        if (session('puzzle_scramble_hint') !== null || session('puzzle_scramble_feedback') !== null) {
            return redirect()->route('user.home.puzzle.scramble');
        }

        session([
            'puzzle_scramble_hint' => [
                'definition' => $puzzle['hint_definition'] ?? '',
                'part_of_speech' => $puzzle['hint_part_of_speech'] ?? null,
            ],
        ]);

        return redirect()->route('user.home.puzzle.scramble');
    }

    public function answerScramble(Request $request): RedirectResponse
    {
        $puzzle = session('puzzle_scramble');

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.scramble');
        }

        if (session('puzzle_scramble_feedback') !== null) {
            return redirect()->route('user.home.puzzle.scramble');
        }

        $data = $request->validate([
            'answer' => ['required', 'string', 'max:40'],
        ]);

        $correctWord = strtolower(trim((string) ($puzzle['correct_word'] ?? '')));
        $answer = strtolower(trim($data['answer']));
        $correct = $correctWord !== '' && $answer === $correctWord;
        $vocabularyId = (int) ($puzzle['vocabulary_id'] ?? 0);

        $this->commands->dispatch(new RecordQuizAttempt(
            userId: $request->user()->id,
            vocabularyId: $vocabularyId,
            questionType: 'scramble',
            correct: $correct,
        ));

        $reveal = $this->queries->ask(new GetUserVocabulary(
            userId: $request->user()->id,
            vocabularyId: $vocabularyId,
        ));

        $startedAt = (int) session('puzzle_scramble_started_at', now()->timestamp);
        $elapsed = max(0, now()->timestamp - $startedAt);

        $sessionUpdate = [
            'puzzle_scramble_feedback' => $correct ? 'Correct!' : 'Incorrect. Answer: '.$correctWord,
            'puzzle_scramble_was_correct' => $correct,
            'puzzle_scramble_reveal' => is_array($reveal) ? $reveal : null,
            'puzzle_scramble_elapsed' => $elapsed,
            'puzzle_scramble_celebrate_record' => null,
        ];

        if ($correct) {
            $sessionCorrect = (int) session('puzzle_scramble_session_correct', 0) + 1;
            $sessionUpdate['puzzle_scramble_session_correct'] = $sessionCorrect;

            $bump = GameRecord::bumpIfBetter($request->user()->id, GameRecord::GAME_SCRAMBLE, $sessionCorrect);
            if ($bump['is_new_record']) {
                $sessionUpdate['puzzle_scramble_celebrate_record'] = $sessionCorrect;
            }
        }

        session($sessionUpdate);

        return redirect()->route('user.home.puzzle.scramble');
    }

    public function wordle(Request $request): Response|RedirectResponse
    {
        if ($request->query('autostart') === '1' && ! session()->has('puzzle_wordle')) {
            return $this->nextWordle($request);
        }

        $reveal = session('puzzle_wordle_reveal');
        $startedAt = session('puzzle_wordle_started_at');
        $puzzle = $this->ensureWordleKeyboard(session('puzzle_wordle'));

        /** @var User $user */
        $user = $request->user();
        $celebrateRecord = session('puzzle_wordle_celebrate_record');
        session()->forget('puzzle_wordle_celebrate_record');

        $clientPuzzle = is_array($puzzle) ? $puzzle : null;
        if (is_array($clientPuzzle)) {
            unset($clientPuzzle['correct_word']);
        }

        return Inertia::render('Puzzle/Wordle', [
            'puzzle' => $clientPuzzle,
            'guesses' => session('puzzle_wordle_guesses', []),
            'hint' => session('puzzle_wordle_hint'),
            'hintAt' => session('puzzle_wordle_hint_at'),
            'feedback' => session('puzzle_wordle_feedback'),
            'wasCorrect' => session('puzzle_wordle_was_correct'),
            'reveal' => is_array($reveal) ? $reveal : null,
            'startedAt' => is_numeric($startedAt) ? (int) $startedAt : null,
            'elapsedSeconds' => session('puzzle_wordle_elapsed'),
            'sessionCorrect' => (int) session('puzzle_wordle_session_correct', 0),
            'bestCorrect' => GameRecord::bestCorrectFor($user->id, GameRecord::GAME_WORDLE),
            'celebrateRecord' => $celebrateRecord,
        ]);
    }

    public function nextWordle(Request $request): RedirectResponse
    {
        $seenIds = session('puzzle_wordle_seen_ids', []);
        if (! is_array($seenIds)) {
            $seenIds = [];
        }
        $seenIds = array_values(array_unique(array_map('intval', $seenIds)));

        $puzzle = $this->queries->ask(new GetNextWordlePuzzle(
            userId: $request->user()->id,
            excludeVocabularyIds: $seenIds,
        ));

        if (! $puzzle) {
            $this->clearWordleSession();

            return redirect()
                ->route('user.home.puzzle.wordle')
                ->with('error', 'You need at least one saved 5-letter word to play Wordle. Add words in Vocabulary.');
        }

        $vocabularyId = (int) ($puzzle['vocabulary_id'] ?? 0);

        // Handler falls back to the full pool when every eligible word was already
        // seen this run — start a fresh cycle from the newly chosen word.
        if ($vocabularyId > 0 && in_array($vocabularyId, $seenIds, true)) {
            $seenIds = [];
        }
        if ($vocabularyId > 0) {
            $seenIds[] = $vocabularyId;
            $seenIds = array_values(array_unique($seenIds));
        }

        $hint = $this->queries->ask(new GetScrambleHint(
            userId: $request->user()->id,
            vocabularyId: $vocabularyId,
        ));
        $hintAt = now()->timestamp;

        $payload = [
            'puzzle_wordle' => $puzzle,
            'puzzle_wordle_guesses' => [],
            'puzzle_wordle_hint' => [
                'definition' => $hint['definition'] ?? '',
                'part_of_speech' => $hint['part_of_speech'] ?? null,
            ],
            'puzzle_wordle_hint_at' => $hintAt,
            'puzzle_wordle_feedback' => null,
            'puzzle_wordle_was_correct' => null,
            'puzzle_wordle_reveal' => null,
            'puzzle_wordle_elapsed' => null,
            'puzzle_wordle_celebrate_record' => null,
            'puzzle_wordle_seen_ids' => $seenIds,
        ];

        if (! session()->has('puzzle_wordle_started_at')) {
            $payload['puzzle_wordle_started_at'] = now()->timestamp;
            $payload['puzzle_wordle_session_correct'] = 0;
        }

        session($payload);

        return redirect()->route('user.home.puzzle.wordle');
    }

    public function hintWordle(Request $request): RedirectResponse
    {
        $puzzle = $this->ensureWordleKeyboard(session('puzzle_wordle'));

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        if (session('puzzle_wordle_feedback') !== null) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        $hintAt = session('puzzle_wordle_hint_at');
        if (is_numeric($hintAt) && (now()->timestamp - (int) $hintAt) < WordleGrader::HINT_COOLDOWN_SECONDS) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        $hint = $this->queries->ask(new GetScrambleHint(
            userId: $request->user()->id,
            vocabularyId: (int) ($puzzle['vocabulary_id'] ?? 0),
        ));

        session([
            'puzzle_wordle_hint' => [
                'definition' => $hint['definition'] ?? '',
                'part_of_speech' => $hint['part_of_speech'] ?? null,
            ],
            'puzzle_wordle_hint_at' => now()->timestamp,
        ]);

        return redirect()->route('user.home.puzzle.wordle');
    }

    public function guessWordle(Request $request): RedirectResponse
    {
        $puzzle = $this->ensureWordleKeyboard(session('puzzle_wordle'));

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        if (session('puzzle_wordle_feedback') !== null) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        $data = $request->validate([
            'guess' => ['required', 'string', 'max:10'],
        ]);

        $guess = strtolower(trim($data['guess']));
        $correctWord = strtolower(trim((string) ($puzzle['correct_word'] ?? '')));
        $keyboard = is_array($puzzle['keyboard_letters'] ?? null) ? $puzzle['keyboard_letters'] : [];

        if (! WordleGrader::isValidGuess($guess)) {
            return redirect()
                ->route('user.home.puzzle.wordle')
                ->with('error', 'Enter a 5-letter word (a–z only).');
        }

        if (! WordleKeyboardBuilder::isGuessAllowed($guess, $keyboard)) {
            return redirect()
                ->route('user.home.puzzle.wordle')
                ->with('error', 'Use only the letters on the keyboard.');
        }

        $maxGuesses = (int) ($puzzle['max_guesses'] ?? WordleGrader::MAX_GUESSES);
        $guesses = session('puzzle_wordle_guesses', []);
        if (! is_array($guesses)) {
            $guesses = [];
        }

        if (count($guesses) >= $maxGuesses) {
            return redirect()->route('user.home.puzzle.wordle');
        }

        $tiles = WordleGrader::grade($correctWord, $guess);
        $guesses[] = [
            'guess' => $guess,
            'tiles' => $tiles,
        ];

        $won = $guess === $correctWord;
        $lost = ! $won && count($guesses) >= $maxGuesses;
        $sessionUpdate = [
            'puzzle_wordle_guesses' => $guesses,
        ];

        if ($won || $lost) {
            $vocabularyId = (int) ($puzzle['vocabulary_id'] ?? 0);

            $this->commands->dispatch(new RecordQuizAttempt(
                userId: $request->user()->id,
                vocabularyId: $vocabularyId,
                questionType: 'wordle',
                correct: $won,
            ));

            $reveal = $this->queries->ask(new GetUserVocabulary(
                userId: $request->user()->id,
                vocabularyId: $vocabularyId,
            ));

            $startedAt = (int) session('puzzle_wordle_started_at', now()->timestamp);
            $elapsed = max(0, now()->timestamp - $startedAt);

            $sessionUpdate['puzzle_wordle_feedback'] = $won
                ? 'You got it!'
                : 'Out of guesses. Answer: '.$correctWord;
            $sessionUpdate['puzzle_wordle_was_correct'] = $won;
            $sessionUpdate['puzzle_wordle_reveal'] = is_array($reveal) ? $reveal : null;
            $sessionUpdate['puzzle_wordle_elapsed'] = $elapsed;
            $sessionUpdate['puzzle_wordle_celebrate_record'] = null;

            if ($won) {
                $sessionCorrect = (int) session('puzzle_wordle_session_correct', 0) + 1;
                $sessionUpdate['puzzle_wordle_session_correct'] = $sessionCorrect;

                $bump = GameRecord::bumpIfBetter($request->user()->id, GameRecord::GAME_WORDLE, $sessionCorrect);
                if ($bump['is_new_record']) {
                    $sessionUpdate['puzzle_wordle_celebrate_record'] = $sessionCorrect;
                }
            }
        }

        session($sessionUpdate);

        return redirect()->route('user.home.puzzle.wordle');
    }

    public function hangman(Request $request): Response|RedirectResponse
    {
        if ($request->query('autostart') === '1' && ! session()->has('puzzle_hangman')) {
            return $this->nextHangman($request);
        }

        $reveal = session('puzzle_hangman_reveal');
        $startedAt = session('puzzle_hangman_started_at');
        $guessedLetters = session('puzzle_hangman_guessed', []);
        if (! is_array($guessedLetters)) {
            $guessedLetters = [];
        }

        /** @var User $user */
        $user = $request->user();
        $celebrateRecord = session('puzzle_hangman_celebrate_record');
        session()->forget('puzzle_hangman_celebrate_record');

        $puzzle = session('puzzle_hangman');
        if (is_array($puzzle)) {
            $correctWord = strtolower(trim((string) ($puzzle['correct_word'] ?? '')));
            $puzzle['mask'] = HangmanGrader::mask($correctWord, $guessedLetters);
            $puzzle['wrong_count'] = HangmanGrader::wrongCount($correctWord, $guessedLetters);
            unset($puzzle['correct_word']);
        }

        return Inertia::render('Puzzle/Hangman', [
            'puzzle' => $puzzle,
            'guessedLetters' => $guessedLetters,
            'feedback' => session('puzzle_hangman_feedback'),
            'wasCorrect' => session('puzzle_hangman_was_correct'),
            'reveal' => is_array($reveal) ? $reveal : null,
            'startedAt' => is_numeric($startedAt) ? (int) $startedAt : null,
            'elapsedSeconds' => session('puzzle_hangman_elapsed'),
            'sessionCorrect' => (int) session('puzzle_hangman_session_correct', 0),
            'bestCorrect' => GameRecord::bestCorrectFor($user->id, GameRecord::GAME_HANGMAN),
            'celebrateRecord' => $celebrateRecord,
        ]);
    }

    public function nextHangman(Request $request): RedirectResponse
    {
        $puzzle = $this->queries->ask(new GetNextHangmanPuzzle($request->user()->id));

        if (! $puzzle) {
            $this->clearHangmanSession();

            return redirect()
                ->route('user.home.puzzle.hangman')
                ->with('error', 'You need at least one saved single word (3–12 letters) to play Hangman. Add words in Vocabulary.');
        }

        $payload = [
            'puzzle_hangman' => $puzzle,
            'puzzle_hangman_guessed' => [],
            'puzzle_hangman_feedback' => null,
            'puzzle_hangman_was_correct' => null,
            'puzzle_hangman_reveal' => null,
            'puzzle_hangman_elapsed' => null,
            'puzzle_hangman_celebrate_record' => null,
        ];

        if (! session()->has('puzzle_hangman_started_at')) {
            $payload['puzzle_hangman_started_at'] = now()->timestamp;
            $payload['puzzle_hangman_session_correct'] = 0;
        }

        session($payload);

        return redirect()->route('user.home.puzzle.hangman');
    }

    public function guessHangman(Request $request): RedirectResponse
    {
        $puzzle = session('puzzle_hangman');

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.hangman');
        }

        if (session('puzzle_hangman_feedback') !== null) {
            return redirect()->route('user.home.puzzle.hangman');
        }

        $data = $request->validate([
            'letter' => ['required', 'string', 'size:1'],
        ]);

        $letter = strtolower(trim($data['letter']));
        if (! HangmanGrader::isValidLetter($letter)) {
            return redirect()
                ->route('user.home.puzzle.hangman')
                ->with('error', 'Pick a letter A–Z.');
        }

        $guessedLetters = session('puzzle_hangman_guessed', []);
        if (! is_array($guessedLetters)) {
            $guessedLetters = [];
        }

        $correctWord = strtolower(trim((string) ($puzzle['correct_word'] ?? '')));
        $result = HangmanGrader::applyGuess($correctWord, $guessedLetters, $letter);

        if ($result === null) {
            return redirect()->route('user.home.puzzle.hangman');
        }

        $sessionUpdate = [
            'puzzle_hangman_guessed' => $result['guessed_letters'],
        ];

        if ($result['finished']) {
            $vocabularyId = (int) ($puzzle['vocabulary_id'] ?? 0);
            $won = $result['won'];

            $this->commands->dispatch(new RecordQuizAttempt(
                userId: $request->user()->id,
                vocabularyId: $vocabularyId,
                questionType: 'hangman',
                correct: $won,
            ));

            $reveal = $this->queries->ask(new GetUserVocabulary(
                userId: $request->user()->id,
                vocabularyId: $vocabularyId,
            ));

            $startedAt = (int) session('puzzle_hangman_started_at', now()->timestamp);
            $elapsed = max(0, now()->timestamp - $startedAt);

            $sessionUpdate['puzzle_hangman_feedback'] = $won
                ? 'You got it!'
                : 'Out of lives. Answer: '.$correctWord;
            $sessionUpdate['puzzle_hangman_was_correct'] = $won;
            $sessionUpdate['puzzle_hangman_reveal'] = is_array($reveal) ? $reveal : null;
            $sessionUpdate['puzzle_hangman_elapsed'] = $elapsed;
            $sessionUpdate['puzzle_hangman_celebrate_record'] = null;

            if ($won) {
                $sessionCorrect = (int) session('puzzle_hangman_session_correct', 0) + 1;
                $sessionUpdate['puzzle_hangman_session_correct'] = $sessionCorrect;

                $bump = GameRecord::bumpIfBetter($request->user()->id, GameRecord::GAME_HANGMAN, $sessionCorrect);
                if ($bump['is_new_record']) {
                    $sessionUpdate['puzzle_hangman_celebrate_record'] = $sessionCorrect;
                }
            }
        }

        session($sessionUpdate);

        return redirect()->route('user.home.puzzle.hangman');
    }

    public function wordSearch(Request $request): Response|RedirectResponse
    {
        if ($request->query('autostart') === '1' && ! session()->has('puzzle_word_search')) {
            return $this->nextWordSearch($request);
        }

        $reveal = session('puzzle_word_search_reveal');
        $startedAt = session('puzzle_word_search_started_at');
        $foundIds = session('puzzle_word_search_found', []);
        if (! is_array($foundIds)) {
            $foundIds = [];
        }
        $foundCells = session('puzzle_word_search_found_cells', []);
        if (! is_array($foundCells)) {
            $foundCells = [];
        }

        /** @var User $user */
        $user = $request->user();
        $celebrateRecord = session('puzzle_word_search_celebrate_record');
        session()->forget('puzzle_word_search_celebrate_record');

        $puzzle = session('puzzle_word_search');
        $finished = session('puzzle_word_search_feedback') !== null;
        $puzzle = $this->publicWordSearchPuzzle(
            is_array($puzzle) ? $puzzle : null,
            $foundIds,
            $finished,
        );

        $hintCell = session('puzzle_word_search_hint_cell');
        if (! is_array($hintCell) || ! isset($hintCell['r'], $hintCell['c'])) {
            $hintCell = null;
        } else {
            $hintCell = ['r' => (int) $hintCell['r'], 'c' => (int) $hintCell['c']];
        }

        return Inertia::render('Puzzle/WordSearch', [
            'puzzle' => $puzzle,
            'foundIds' => array_values(array_map('intval', $foundIds)),
            'foundCells' => $foundCells,
            'hintCell' => $hintCell,
            'hintAt' => is_numeric(session('puzzle_word_search_hint_at')) ? (int) session('puzzle_word_search_hint_at') : null,
            'feedback' => session('puzzle_word_search_feedback'),
            'wasCorrect' => session('puzzle_word_search_was_correct'),
            'reveal' => is_array($reveal) ? $reveal : null,
            'startedAt' => is_numeric($startedAt) ? (int) $startedAt : null,
            'elapsedSeconds' => session('puzzle_word_search_elapsed'),
            'sessionCorrect' => (int) session('puzzle_word_search_session_correct', 0),
            'bestCorrect' => GameRecord::bestCorrectFor($user->id, GameRecord::GAME_WORD_SEARCH),
            'celebrateRecord' => $celebrateRecord,
        ]);
    }

    public function nextWordSearch(Request $request): RedirectResponse
    {
        $puzzle = $this->queries->ask(new GetNextWordSearchPuzzle($request->user()->id));

        if (! $puzzle) {
            $this->clearWordSearchSession();

            return redirect()
                ->route('user.home.puzzle.word-search')
                ->with('error', 'You need at least '.WordSearchGrader::MIN_WORDS.' saved single words (3–8 letters) to play Word Search. Add words in Vocabulary.');
        }

        $payload = [
            'puzzle_word_search' => $puzzle,
            'puzzle_word_search_found' => [],
            'puzzle_word_search_found_cells' => [],
            'puzzle_word_search_hint_cell' => null,
            'puzzle_word_search_hint_at' => null,
            'puzzle_word_search_hint_word_id' => null,
            'puzzle_word_search_hinted_cells' => [],
            'puzzle_word_search_feedback' => null,
            'puzzle_word_search_was_correct' => null,
            'puzzle_word_search_reveal' => null,
            'puzzle_word_search_elapsed' => null,
            'puzzle_word_search_celebrate_record' => null,
        ];

        if (! session()->has('puzzle_word_search_started_at')) {
            $payload['puzzle_word_search_started_at'] = now()->timestamp;
            $payload['puzzle_word_search_session_correct'] = 0;
        }

        session($payload);

        return redirect()->route('user.home.puzzle.word-search');
    }

    public function findWordSearch(Request $request): RedirectResponse
    {
        $puzzle = session('puzzle_word_search');

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        if (session('puzzle_word_search_feedback') !== null) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        $data = $request->validate([
            'cells' => ['required', 'array', 'min:3'],
            'cells.*.r' => ['required', 'integer', 'min:0', 'max:'.(WordSearchGrader::GRID_SIZE - 1)],
            'cells.*.c' => ['required', 'integer', 'min:0', 'max:'.(WordSearchGrader::GRID_SIZE - 1)],
        ]);

        $foundIds = session('puzzle_word_search_found', []);
        if (! is_array($foundIds)) {
            $foundIds = [];
        }

        $placements = is_array($puzzle['placements'] ?? null) ? $puzzle['placements'] : [];
        $result = WordSearchGrader::applyFind($placements, $foundIds, $data['cells']);

        if ($result === null) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        if (! $result['hit']) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        $foundCells = session('puzzle_word_search_found_cells', []);
        if (! is_array($foundCells)) {
            $foundCells = [];
        }
        $foundCells[(string) $result['vocabulary_id']] = $result['cells'];

        $sessionUpdate = [
            'puzzle_word_search_found' => $result['found_ids'],
            'puzzle_word_search_found_cells' => $foundCells,
        ];

        $hintWordId = session('puzzle_word_search_hint_word_id');
        if (is_numeric($hintWordId) && (int) $hintWordId === (int) $result['vocabulary_id']) {
            $sessionUpdate['puzzle_word_search_hint_word_id'] = null;
            $sessionUpdate['puzzle_word_search_hinted_cells'] = [];
        }

        $this->commands->dispatch(new RecordQuizAttempt(
            userId: $request->user()->id,
            vocabularyId: (int) $result['vocabulary_id'],
            questionType: 'word_search',
            correct: true,
        ));

        if ($result['finished']) {
            $startedAt = (int) session('puzzle_word_search_started_at', now()->timestamp);
            $elapsed = max(0, now()->timestamp - $startedAt);

            $sessionUpdate['puzzle_word_search_feedback'] = 'Nice! You found every word.';
            $sessionUpdate['puzzle_word_search_was_correct'] = true;
            $sessionUpdate['puzzle_word_search_reveal'] = [
                'words' => $puzzle['words'] ?? [],
            ];
            $sessionUpdate['puzzle_word_search_elapsed'] = $elapsed;
            $sessionUpdate['puzzle_word_search_celebrate_record'] = null;

            $sessionCorrect = (int) session('puzzle_word_search_session_correct', 0) + 1;
            $sessionUpdate['puzzle_word_search_session_correct'] = $sessionCorrect;

            $bump = GameRecord::bumpIfBetter($request->user()->id, GameRecord::GAME_WORD_SEARCH, $sessionCorrect);
            if ($bump['is_new_record']) {
                $sessionUpdate['puzzle_word_search_celebrate_record'] = $sessionCorrect;
            }
        }

        session($sessionUpdate);

        return redirect()->route('user.home.puzzle.word-search');
    }

    public function hintWordSearch(Request $request): RedirectResponse
    {
        $puzzle = session('puzzle_word_search');

        if (! is_array($puzzle)) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        if (session('puzzle_word_search_feedback') !== null) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        $hintAt = session('puzzle_word_search_hint_at');
        if (is_numeric($hintAt) && (now()->timestamp - (int) $hintAt) < WordSearchGrader::HINT_COOLDOWN_SECONDS) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        $foundIds = session('puzzle_word_search_found', []);
        if (! is_array($foundIds)) {
            $foundIds = [];
        }

        $placements = is_array($puzzle['placements'] ?? null) ? $puzzle['placements'] : [];
        $preferredWordId = session('puzzle_word_search_hint_word_id');
        $preferredWordId = is_numeric($preferredWordId) ? (int) $preferredWordId : null;
        if ($preferredWordId !== null && in_array($preferredWordId, array_map('intval', $foundIds), true)) {
            $preferredWordId = null;
        }

        $hintedCells = session('puzzle_word_search_hinted_cells', []);
        if (! is_array($hintedCells)) {
            $hintedCells = [];
        }
        $hintedCells = array_values(array_filter($hintedCells, function ($cell) {
            return is_array($cell) && isset($cell['r'], $cell['c']);
        }));

        $hint = WordSearchGrader::pickHintCell($placements, $foundIds, $preferredWordId, $hintedCells);

        if ($hint === null) {
            return redirect()->route('user.home.puzzle.word-search');
        }

        $hintedCells[] = ['r' => $hint['r'], 'c' => $hint['c']];

        session([
            'puzzle_word_search_hint_cell' => ['r' => $hint['r'], 'c' => $hint['c']],
            'puzzle_word_search_hint_at' => now()->timestamp,
            'puzzle_word_search_hint_word_id' => $hint['vocabulary_id'],
            'puzzle_word_search_hinted_cells' => $hintedCells,
        ]);

        return redirect()->route('user.home.puzzle.word-search');
    }

    private function clearWordSearchSession(): void
    {
        session()->forget([
            'puzzle_word_search',
            'puzzle_word_search_found',
            'puzzle_word_search_found_cells',
            'puzzle_word_search_hint_cell',
            'puzzle_word_search_hint_at',
            'puzzle_word_search_hint_word_id',
            'puzzle_word_search_hinted_cells',
            'puzzle_word_search_feedback',
            'puzzle_word_search_was_correct',
            'puzzle_word_search_reveal',
            'puzzle_word_search_started_at',
            'puzzle_word_search_elapsed',
            'puzzle_word_search_session_correct',
            'puzzle_word_search_celebrate_record',
        ]);
    }

    /**
     * Hide answer words until found; keep meanings visible as clues.
     *
     * @param  array<string, mixed>|null  $puzzle
     * @param  list<int|string>  $foundIds
     * @return array<string, mixed>|null
     */
    private function publicWordSearchPuzzle(?array $puzzle, array $foundIds, bool $finished = false): ?array
    {
        if (! is_array($puzzle)) {
            return null;
        }

        unset($puzzle['placements']);

        $found = [];
        foreach ($foundIds as $id) {
            $found[(int) $id] = true;
        }

        $puzzle['words'] = array_values(array_map(
            function ($word) use ($found, $finished) {
                if (! is_array($word)) {
                    return $word;
                }

                $vocabularyId = (int) ($word['vocabulary_id'] ?? 0);
                $public = [
                    'vocabulary_id' => $vocabularyId,
                    'length' => (int) ($word['length'] ?? strlen((string) ($word['word'] ?? ''))),
                    'definition' => (string) ($word['definition'] ?? 'Find this word in the grid.'),
                    'part_of_speech' => $word['part_of_speech'] ?? null,
                ];

                if ($finished || isset($found[$vocabularyId])) {
                    $public['word'] = (string) ($word['word'] ?? '');
                }

                return $public;
            },
            is_array($puzzle['words'] ?? null) ? $puzzle['words'] : [],
        ));

        return $puzzle;
    }

    private function clearHangmanSession(): void
    {
        session()->forget([
            'puzzle_hangman',
            'puzzle_hangman_guessed',
            'puzzle_hangman_feedback',
            'puzzle_hangman_was_correct',
            'puzzle_hangman_reveal',
            'puzzle_hangman_started_at',
            'puzzle_hangman_elapsed',
            'puzzle_hangman_session_correct',
            'puzzle_hangman_celebrate_record',
        ]);
    }

    private function clearWordleSession(): void
    {
        session()->forget([
            'puzzle_wordle',
            'puzzle_wordle_guesses',
            'puzzle_wordle_hint',
            'puzzle_wordle_hint_at',
            'puzzle_wordle_feedback',
            'puzzle_wordle_was_correct',
            'puzzle_wordle_reveal',
            'puzzle_wordle_started_at',
            'puzzle_wordle_elapsed',
            'puzzle_wordle_session_correct',
            'puzzle_wordle_celebrate_record',
            'puzzle_wordle_seen_ids',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $puzzle
     * @return array<string, mixed>|null
     */
    private function ensureWordleKeyboard(?array $puzzle): ?array
    {
        if (! is_array($puzzle)) {
            return null;
        }

        $correctWord = strtolower(trim((string) ($puzzle['correct_word'] ?? '')));
        $keyboard = $puzzle['keyboard_letters'] ?? null;

        if ($correctWord === '' || (is_array($keyboard) && $keyboard !== [])) {
            return $puzzle;
        }

        $puzzle['keyboard_letters'] = WordleKeyboardBuilder::build(
            $correctWord,
            null,
            (int) ($puzzle['vocabulary_id'] ?? 0),
        );
        session(['puzzle_wordle' => $puzzle]);

        return $puzzle;
    }

    private function clearScrambleSession(): void
    {
        session()->forget([
            'puzzle_scramble',
            'puzzle_scramble_hint',
            'puzzle_scramble_feedback',
            'puzzle_scramble_was_correct',
            'puzzle_scramble_reveal',
            'puzzle_scramble_started_at',
            'puzzle_scramble_word_started_at',
            'puzzle_scramble_elapsed',
            'puzzle_scramble_session_correct',
            'puzzle_scramble_celebrate_record',
        ]);
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
