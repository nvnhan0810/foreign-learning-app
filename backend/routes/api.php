<?php

use App\Http\Controllers\Api\AppConfigController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\SsoAuthController;
use App\Http\Controllers\Api\WebviewSessionController;
use App\Http\Controllers\Api\ListeningAssessmentController;
use App\Http\Controllers\Api\ListeningMediaController;
use App\Http\Controllers\Api\DevicePushTokenController;
use App\Http\Controllers\Api\MediaItemController;
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PuzzleController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\VocabularyController;
use App\Http\Controllers\Api\WordChatController;
use Illuminate\Support\Facades\Route;

Route::get('/config', AppConfigController::class);

Route::get('/auth/sso/redirect', [SsoAuthController::class, 'redirect']);
Route::post('/auth/sso/exchange', [SsoAuthController::class, 'exchange']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/auth/webview-session', [WebviewSessionController::class, 'store']);
    Route::get('/profile', [ProfileController::class, 'show']);

    Route::post('/me/push-token', [DevicePushTokenController::class, 'store']);
    Route::delete('/me/push-token', [DevicePushTokenController::class, 'destroy']);
    Route::get('/me/notification-settings', [NotificationSettingsController::class, 'show']);
    Route::put('/me/notification-settings', [NotificationSettingsController::class, 'update']);

    Route::get('/dictionary/resolve/{word}', [DictionaryController::class, 'resolve'])
        ->where('word', '.*');
    Route::get('/dictionary/{word}', [DictionaryController::class, 'show'])
        ->where('word', '.*');

    Route::prefix('word-chat')->group(function () {
        Route::get('/agent', [WordChatController::class, 'agentStatus']);
        Route::post('/agent/ensure', [WordChatController::class, 'ensureAgent']);
        Route::get('/messages', [WordChatController::class, 'index']);
        Route::get('/insights', [WordChatController::class, 'insights']);
        Route::post('/messages', [WordChatController::class, 'store']);
        Route::get('/stream/{runId}', [WordChatController::class, 'stream']);
        Route::post('/reset', [WordChatController::class, 'reset']);
    });

    Route::apiResource('vocabularies', VocabularyController::class);

    Route::get('/media-items/due', [MediaItemController::class, 'due']);
    Route::post('/media-items/{mediaItem}/listened', [MediaItemController::class, 'listened']);
    Route::apiResource('media-items', MediaItemController::class);

    // Listening: save YouTube/MP3 (analysis + assessments run in background on store)
    Route::prefix('listening')->group(function () {
        Route::post('/media/youtube-preview', [ListeningMediaController::class, 'previewYouTube']);
        Route::post('/media', [ListeningMediaController::class, 'store']);
        Route::get('/media/{mediaItem}', [ListeningMediaController::class, 'show']);
        Route::put('/media/{mediaItem}/transcript', [ListeningMediaController::class, 'updateTranscript']);
        Route::get('/media/{mediaItem}/audio', [ListeningMediaController::class, 'audio']);
        Route::get('/media/{mediaItem}/assessments', [ListeningMediaController::class, 'assessments']);
        Route::get('/media/{mediaItem}/session-options', [ListeningAssessmentController::class, 'sessionOptions']);
        Route::post('/media/{mediaItem}/sessions', [ListeningAssessmentController::class, 'startSession']);

        Route::get('/assessments/{listeningAssessment}', [ListeningAssessmentController::class, 'show']);
        Route::get('/assessments/{listeningAssessment}/questions', [ListeningAssessmentController::class, 'questions']);
        Route::post('/assessments/{listeningAssessment}/attempts', [ListeningAssessmentController::class, 'submitAttempt']);
        Route::get('/assessments/{listeningAssessment}/attempts', [ListeningAssessmentController::class, 'attempts']);
    });

    Route::get('/quiz/next', [QuizController::class, 'next']);
    Route::post('/quiz/attempts', [QuizController::class, 'attempt']);

    Route::get('/puzzle/scramble/next', [PuzzleController::class, 'nextScramble']);
    Route::post('/puzzle/scramble/hint', [PuzzleController::class, 'hintScramble']);
    Route::post('/puzzle/scramble/attempts', [PuzzleController::class, 'attemptScramble']);

    Route::get('/puzzle/wordle/next', [PuzzleController::class, 'nextWordle']);
    Route::post('/puzzle/wordle/guesses', [PuzzleController::class, 'guessWordle']);

    Route::get('/puzzle/hangman/next', [PuzzleController::class, 'nextHangman']);
    Route::post('/puzzle/hangman/guesses', [PuzzleController::class, 'guessHangman']);

    Route::get('/puzzle/word-search/next', [PuzzleController::class, 'nextWordSearch']);
    Route::post('/puzzle/word-search/finds', [PuzzleController::class, 'findWordSearch']);

    Route::get('/sync', [SyncController::class, 'index']);
});
