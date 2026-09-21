<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AllowedEmailController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DictionaryController;
use App\Http\Controllers\Admin\ListeningAssessmentController;
use App\Http\Controllers\Admin\MediaItemController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\DevicePushTokenController;
use App\Http\Controllers\Web\ListeningController;
use App\Http\Controllers\Web\LookupController;
use App\Http\Controllers\Web\MediaController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PuzzleController;
use App\Http\Controllers\Web\QuizController;
use App\Http\Controllers\Web\UserAuthController;
use App\Http\Controllers\Web\VocabularyController as WebVocabularyController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('user.home.lookup');
    }

    return redirect()->route('user.login');
});

Route::name('user.')->middleware(\App\Http\Middleware\DetectFlcMobileApp::class)->group(function () {
    Route::get('login', [UserAuthController::class, 'showLogin'])->name('login');
    Route::get('auth/sso', [UserAuthController::class, 'redirectSso'])->name('auth.sso');
    Route::get('auth/sso/callback', [UserAuthController::class, 'callbackSso'])->name('auth.sso.callback');
    Route::get('auth/webview-handoff', [UserAuthController::class, 'webviewHandoff'])->name('auth.webview.handoff');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [UserAuthController::class, 'logout'])->name('logout');

        Route::prefix('home')->name('home.')->group(function () {
            Route::get('lookup', [LookupController::class, 'index'])->name('lookup');
            Route::post('lookup', [LookupController::class, 'lookup'])->name('lookup.search');
            Route::post('lookup/save', [LookupController::class, 'save'])->name('lookup.save');
            Route::get('dictionary/{word}/pronounce', [LookupController::class, 'pronounce'])->name('dictionary.pronounce');
            Route::get('word/{word}', [LookupController::class, 'openWord'])
                ->where('word', "[A-Za-z][A-Za-z\\s'\\-]*")
                ->name('word.open');

            Route::get('vocab', [WebVocabularyController::class, 'index'])->name('vocab');
            Route::get('vocab/{vocabulary}', [WebVocabularyController::class, 'show'])->name('vocab.show');
            Route::delete('vocab/{vocabulary}', [WebVocabularyController::class, 'destroy'])->name('vocab.destroy');

            Route::get('media', [MediaController::class, 'index'])->name('media');
            Route::post('media/youtube/preview', [MediaController::class, 'previewYouTube'])->name('media.youtube.preview');
            Route::post('media/youtube', [MediaController::class, 'storeYouTube'])->name('media.youtube.store');
            Route::get('media/{mediaItem}/audio', [MediaController::class, 'audio'])->name('media.audio');
            Route::get('media/{mediaItem}', [MediaController::class, 'show'])->name('media.show');
            Route::put('media/{mediaItem}/transcript', [MediaController::class, 'updateTranscript'])->name('media.transcript');

            Route::get('quiz', [QuizController::class, 'index'])->name('quiz');
            Route::get('quiz/play', [QuizController::class, 'play'])->name('quiz.play');
            Route::post('quiz/next', [QuizController::class, 'next'])->name('quiz.next');
            Route::post('quiz/answer', [QuizController::class, 'answer'])->name('quiz.answer');

            Route::get('puzzle', [PuzzleController::class, 'index'])->name('puzzle');
            Route::post('puzzle/exit', [PuzzleController::class, 'exit'])->name('puzzle.exit');
            Route::get('puzzle/scramble', [PuzzleController::class, 'scramble'])->name('puzzle.scramble');
            Route::post('puzzle/scramble/next', [PuzzleController::class, 'nextScramble'])->name('puzzle.scramble.next');
            Route::post('puzzle/scramble/hint', [PuzzleController::class, 'hintScramble'])->name('puzzle.scramble.hint');
            Route::post('puzzle/scramble/answer', [PuzzleController::class, 'answerScramble'])->name('puzzle.scramble.answer');

            Route::get('puzzle/wordle', [PuzzleController::class, 'wordle'])->name('puzzle.wordle');
            Route::post('puzzle/wordle/next', [PuzzleController::class, 'nextWordle'])->name('puzzle.wordle.next');
            Route::post('puzzle/wordle/hint', [PuzzleController::class, 'hintWordle'])->name('puzzle.wordle.hint');
            Route::post('puzzle/wordle/guess', [PuzzleController::class, 'guessWordle'])->name('puzzle.wordle.guess');

            Route::get('puzzle/hangman', [PuzzleController::class, 'hangman'])->name('puzzle.hangman');
            Route::post('puzzle/hangman/next', [PuzzleController::class, 'nextHangman'])->name('puzzle.hangman.next');
            Route::post('puzzle/hangman/guess', [PuzzleController::class, 'guessHangman'])->name('puzzle.hangman.guess');

            Route::get('puzzle/word-search', [PuzzleController::class, 'wordSearch'])->name('puzzle.word-search');
            Route::post('puzzle/word-search/next', [PuzzleController::class, 'nextWordSearch'])->name('puzzle.word-search.next');
            Route::post('puzzle/word-search/find', [PuzzleController::class, 'findWordSearch'])->name('puzzle.word-search.find');
            Route::post('puzzle/word-search/hint', [PuzzleController::class, 'hintWordSearch'])->name('puzzle.word-search.hint');

            Route::get('profile', [ProfileController::class, 'show'])->name('profile');

            Route::post('push-token', [DevicePushTokenController::class, 'store'])->name('push-token.store');
            Route::delete('push-token', [DevicePushTokenController::class, 'destroy'])->name('push-token.destroy');
        });

        Route::get('listening/{listeningAssessment}', [ListeningController::class, 'show'])->name('listening.show');
        Route::post('listening/{listeningAssessment}/submit', [ListeningController::class, 'submit'])->name('listening.submit');
        Route::post('media/{mediaItem}/listening/start', [ListeningController::class, 'start'])->name('listening.start');
    });
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::get('auth/sso', [AdminAuthController::class, 'redirectSso'])->name('auth.sso');
    Route::get('auth/sso/callback', [AdminAuthController::class, 'callbackSso'])->name('auth.sso.callback');

    Route::middleware('admin')->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::resource('allowed-emails', AllowedEmailController::class)->except(['show']);
        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::resource('users', UserController::class)->only(['index', 'show', 'destroy']);
        Route::redirect('vocabularies', '/admin/dictionary');
        Route::any('vocabularies/{any}', fn () => redirect('/admin/dictionary'))
            ->where('any', '.*');
        Route::resource('dictionary', DictionaryController::class)->except(['show']);

        Route::post('media-items/{mediaItem}/process', [MediaItemController::class, 'process'])->name('media-items.process');
        Route::post('media-items/{mediaItem}/regenerate-assessments', [MediaItemController::class, 'regenerateAssessments'])->name('media-items.regenerate-assessments');
        Route::resource('media-items', MediaItemController::class);

        Route::post('listening-assessments/{listeningAssessment}/regenerate', [ListeningAssessmentController::class, 'regenerate'])->name('listening-assessments.regenerate');
        Route::resource('listening-assessments', ListeningAssessmentController::class)->only(['index', 'show', 'destroy']);
    });
});
