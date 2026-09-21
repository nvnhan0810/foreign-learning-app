<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRecord extends Model
{
    public const GAME_QUIZ = 'quiz';

    public const GAME_SCRAMBLE = 'scramble';

    public const GAME_WORDLE = 'wordle';

    public const GAME_HANGMAN = 'hangman';

    public const GAME_WORD_SEARCH = 'word_search';

    protected $fillable = [
        'user_id',
        'game',
        'best_correct',
    ];

    protected function casts(): array
    {
        return [
            'best_correct' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function bestCorrectFor(int $userId, string $game): int
    {
        return (int) static::query()
            ->where('user_id', $userId)
            ->where('game', $game)
            ->value('best_correct');
    }

    /**
     * Persist a new personal best when $correct beats the stored record.
     *
     * @return array{previous: int, best: int, is_new_record: bool}
     */
    public static function bumpIfBetter(int $userId, string $game, int $correct): array
    {
        $record = static::query()->firstOrNew([
            'user_id' => $userId,
            'game' => $game,
        ]);

        $previous = (int) ($record->best_correct ?? 0);
        $isNewRecord = $correct > $previous;

        if ($isNewRecord) {
            $record->best_correct = $correct;
            $record->save();
        }

        return [
            'previous' => $previous,
            'best' => $isNewRecord ? $correct : $previous,
            'is_new_record' => $isNewRecord,
        ];
    }
}
