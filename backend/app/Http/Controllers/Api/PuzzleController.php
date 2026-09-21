<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\Puzzle\Application\Query\GetNextHangmanPuzzle;
use Flc\Puzzle\Application\Query\GetNextScramblePuzzle;
use Flc\Puzzle\Application\Query\GetNextWordSearchPuzzle;
use Flc\Puzzle\Application\Query\GetNextWordlePuzzle;
use Flc\Puzzle\Domain\HangmanGrader;
use Flc\Puzzle\Domain\WordSearchGrader;
use Flc\Puzzle\Domain\WordleGrader;
use Flc\Puzzle\Domain\WordleKeyboardBuilder;
use Flc\Puzzle\Application\Query\GetScrambleHint;
use Flc\Quiz\Application\Command\RecordQuizAttempt;
use Flc\Shared\Application\CommandBus;
use Flc\Shared\Application\QueryBus;
use Flc\Vocabulary\Application\Query\GetUserVocabulary;
use Flc\Vocabulary\Application\Repository\UserVocabularyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PuzzleController extends Controller
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly UserVocabularyRepository $vocabularies,
    ) {}

    public function nextScramble(Request $request): JsonResponse
    {
        $puzzle = $this->queries->ask(new GetNextScramblePuzzle($request->user()->id));

        if (! $puzzle) {
            return response()->json([
                'message' => 'You need at least one saved single word (3–14 letters) to play Scramble.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'vocabulary_id' => $puzzle['vocabulary_id'],
                'mode' => $puzzle['mode'],
                'scrambled' => $puzzle['scrambled'],
                'word_length' => $puzzle['word_length'],
            ],
        ]);
    }

    public function hintScramble(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'integer', 'exists:vocabularies,id'],
        ]);

        $hint = $this->queries->ask(new GetScrambleHint(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
        ));

        return response()->json(['data' => $hint]);
    }

    public function attemptScramble(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'integer', 'exists:vocabularies,id'],
            'answer' => ['required', 'string', 'max:40'],
        ]);

        $vocabulary = $this->vocabularies->findForUser(
            $request->user()->id,
            (int) $data['vocabulary_id'],
        );

        if ($vocabulary === null) {
            throw new AccessDeniedHttpException();
        }

        $correctWord = strtolower(trim($vocabulary->word));
        $answer = strtolower(trim($data['answer']));
        $correct = $answer === $correctWord;

        $attempt = $this->commands->dispatch(new RecordQuizAttempt(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
            questionType: 'scramble',
            correct: $correct,
        ));

        $entry = $this->queries->ask(new GetUserVocabulary(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
        ));

        return response()->json([
            'data' => [
                'correct' => $correct,
                'correct_word' => $correctWord,
                'attempt' => $attempt,
                'entry' => $entry,
            ],
        ]);
    }

    public function nextWordle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exclude_vocabulary_ids' => ['sometimes', 'array'],
            'exclude_vocabulary_ids.*' => ['integer'],
        ]);

        $exclude = array_values(array_unique(array_map(
            'intval',
            is_array($data['exclude_vocabulary_ids'] ?? null) ? $data['exclude_vocabulary_ids'] : [],
        )));

        $puzzle = $this->queries->ask(new GetNextWordlePuzzle(
            userId: $request->user()->id,
            excludeVocabularyIds: $exclude,
        ));

        if (! $puzzle) {
            return response()->json([
                'message' => 'You need at least one saved 5-letter word to play Wordle.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'vocabulary_id' => $puzzle['vocabulary_id'],
                'mode' => $puzzle['mode'],
                'word_length' => $puzzle['word_length'],
                'max_guesses' => $puzzle['max_guesses'],
                'keyboard_letters' => $puzzle['keyboard_letters'],
                'eligible_count' => $puzzle['eligible_count'] ?? null,
            ],
        ]);
    }

    public function guessWordle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'integer', 'exists:vocabularies,id'],
            'guess' => ['required', 'string', 'max:10'],
            'guesses_used' => ['required', 'integer', 'min:1', 'max:'.WordleGrader::MAX_GUESSES],
        ]);

        $vocabulary = $this->vocabularies->findForUser(
            $request->user()->id,
            (int) $data['vocabulary_id'],
        );

        if ($vocabulary === null) {
            throw new AccessDeniedHttpException();
        }

        $guess = strtolower(trim($data['guess']));
        $correctWord = strtolower(trim($vocabulary->word));
        $vocabularyId = (int) $data['vocabulary_id'];
        $keyboard = WordleKeyboardBuilder::build($correctWord, null, $vocabularyId);

        if (! WordleGrader::isValidGuess($guess)) {
            return response()->json([
                'message' => 'Enter a 5-letter word (a–z only).',
            ], 422);
        }

        if (! WordleKeyboardBuilder::isGuessAllowed($guess, $keyboard)) {
            return response()->json([
                'message' => 'Use only the letters on the keyboard.',
            ], 422);
        }

        $tiles = WordleGrader::grade($correctWord, $guess);
        $won = $guess === $correctWord;
        $finished = $won || (int) $data['guesses_used'] >= WordleGrader::MAX_GUESSES;

        $attempt = null;
        $entry = null;

        if ($finished) {
            $attempt = $this->commands->dispatch(new RecordQuizAttempt(
                userId: $request->user()->id,
                vocabularyId: (int) $data['vocabulary_id'],
                questionType: 'wordle',
                correct: $won,
            ));

            $entry = $this->queries->ask(new GetUserVocabulary(
                userId: $request->user()->id,
                vocabularyId: (int) $data['vocabulary_id'],
            ));
        }

        return response()->json([
            'data' => [
                'tiles' => $tiles,
                'won' => $won,
                'finished' => $finished,
                'correct_word' => $finished ? $correctWord : null,
                'attempt' => $attempt,
                'entry' => $entry,
            ],
        ]);
    }

    public function nextHangman(Request $request): JsonResponse
    {
        $puzzle = $this->queries->ask(new GetNextHangmanPuzzle($request->user()->id));

        if (! $puzzle) {
            return response()->json([
                'message' => 'You need at least one saved single word (3–12 letters) to play Hangman.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'vocabulary_id' => $puzzle['vocabulary_id'],
                'mode' => $puzzle['mode'],
                'word_length' => $puzzle['word_length'],
                'max_wrong' => $puzzle['max_wrong'],
                'mask' => $puzzle['mask'],
                'clue_definition' => $puzzle['clue_definition'],
                'clue_part_of_speech' => $puzzle['clue_part_of_speech'],
            ],
        ]);
    }

    public function guessHangman(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'integer', 'exists:vocabularies,id'],
            'letter' => ['required', 'string', 'size:1'],
            'guessed_letters' => ['nullable', 'array'],
            'guessed_letters.*' => ['string', 'size:1'],
        ]);

        $vocabulary = $this->vocabularies->findForUser(
            $request->user()->id,
            (int) $data['vocabulary_id'],
        );

        if ($vocabulary === null) {
            throw new AccessDeniedHttpException();
        }

        $correctWord = strtolower(trim($vocabulary->word));
        $guessedLetters = is_array($data['guessed_letters'] ?? null) ? $data['guessed_letters'] : [];
        $letter = strtolower(trim($data['letter']));

        if (! HangmanGrader::isValidLetter($letter)) {
            return response()->json([
                'message' => 'Pick a letter A–Z.',
            ], 422);
        }

        $result = HangmanGrader::applyGuess($correctWord, $guessedLetters, $letter);
        if ($result === null) {
            return response()->json([
                'message' => 'Letter already guessed.',
            ], 422);
        }

        $attempt = null;
        $entry = null;

        if ($result['finished']) {
            $attempt = $this->commands->dispatch(new RecordQuizAttempt(
                userId: $request->user()->id,
                vocabularyId: (int) $data['vocabulary_id'],
                questionType: 'hangman',
                correct: $result['won'],
            ));

            $entry = $this->queries->ask(new GetUserVocabulary(
                userId: $request->user()->id,
                vocabularyId: (int) $data['vocabulary_id'],
            ));
        }

        return response()->json([
            'data' => [
                'hit' => $result['hit'],
                'guessed_letters' => $result['guessed_letters'],
                'wrong_count' => $result['wrong_count'],
                'mask' => $result['mask'],
                'won' => $result['won'],
                'lost' => $result['lost'],
                'finished' => $result['finished'],
                'correct_word' => $result['finished'] ? $correctWord : null,
                'attempt' => $attempt,
                'entry' => $entry,
            ],
        ]);
    }

    public function nextWordSearch(Request $request): JsonResponse
    {
        $puzzle = $this->queries->ask(new GetNextWordSearchPuzzle($request->user()->id));

        if (! $puzzle) {
            return response()->json([
                'message' => 'You need at least '.WordSearchGrader::MIN_WORDS.' saved single words (3–8 letters) to play Word Search.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'mode' => $puzzle['mode'],
                'grid_size' => $puzzle['grid_size'],
                'grid' => $puzzle['grid'],
                'words' => array_map(
                    fn (array $word) => [
                        'vocabulary_id' => $word['vocabulary_id'],
                        'length' => $word['length'],
                        'definition' => $word['definition'] ?? 'Find this word in the grid.',
                        'part_of_speech' => $word['part_of_speech'] ?? null,
                    ],
                    is_array($puzzle['words'] ?? null) ? $puzzle['words'] : [],
                ),
            ],
        ]);
    }

    public function findWordSearch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vocabulary_id' => ['required', 'integer', 'exists:vocabularies,id'],
            'cells' => ['required', 'array', 'min:3'],
            'cells.*.r' => ['required', 'integer', 'min:0'],
            'cells.*.c' => ['required', 'integer', 'min:0'],
            'grid' => ['required', 'array', 'min:1'],
            'grid.*' => ['required', 'array', 'min:1'],
            'found_ids' => ['nullable', 'array'],
            'found_ids.*' => ['integer'],
        ]);

        $vocabulary = $this->vocabularies->findForUser(
            $request->user()->id,
            (int) $data['vocabulary_id'],
        );

        if ($vocabulary === null) {
            throw new AccessDeniedHttpException();
        }

        $word = strtolower(trim($vocabulary->word));
        $foundIds = is_array($data['found_ids'] ?? null) ? $data['found_ids'] : [];
        if (in_array((int) $data['vocabulary_id'], array_map('intval', $foundIds), true)) {
            return response()->json([
                'message' => 'Word already found.',
            ], 422);
        }

        if (! WordSearchGrader::pathSpellsWord($data['grid'], $data['cells'], $word)) {
            return response()->json([
                'data' => [
                    'hit' => false,
                    'vocabulary_id' => null,
                    'finished' => false,
                    'won' => false,
                    'found_ids' => array_values(array_map('intval', $foundIds)),
                ],
            ]);
        }

        $foundIds[] = (int) $data['vocabulary_id'];
        $foundIds = array_values(array_unique(array_map('intval', $foundIds)));

        $attempt = $this->commands->dispatch(new RecordQuizAttempt(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
            questionType: 'word_search',
            correct: true,
        ));

        $entry = $this->queries->ask(new GetUserVocabulary(
            userId: $request->user()->id,
            vocabularyId: (int) $data['vocabulary_id'],
        ));

        return response()->json([
            'data' => [
                'hit' => true,
                'vocabulary_id' => (int) $data['vocabulary_id'],
                'word' => $word,
                'found_ids' => $foundIds,
                'finished' => false,
                'won' => false,
                'attempt' => $attempt,
                'entry' => $entry,
            ],
        ]);
    }
}
