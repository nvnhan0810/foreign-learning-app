<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaItem extends Model
{
    public const TYPE_YOUTUBE = 'youtube';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_MP3 = 'mp3';

    public const ANALYSIS_PENDING = 'pending';

    public const ANALYSIS_PROCESSING = 'processing';

    public const ANALYSIS_READY = 'ready';

    public const ANALYSIS_FAILED = 'failed';

    public const QUESTION_BANK_PENDING = 'pending';

    public const QUESTION_BANK_GENERATING = 'generating';

    public const QUESTION_BANK_READY = 'ready';

    public const QUESTION_BANK_FAILED = 'failed';

    public const DIFFICULTY_BEGINNER = 'beginner';

    public const DIFFICULTY_INTERMEDIATE = 'intermediate';

    public const DIFFICULTY_ADVANCED = 'advanced';

    /** @var list<string> */
    public const DIFFICULTIES = [
        self::DIFFICULTY_BEGINNER,
        self::DIFFICULTY_INTERMEDIATE,
        self::DIFFICULTY_ADVANCED,
    ];

    protected $fillable = [
        'user_id',
        'title',
        'url',
        'source_id',
        'audio_path',
        'audio_disk',
        'duration_seconds',
        'language',
        'analysis_status',
        'analysis_error',
        'transcript',
        'analysis_payload',
        'analyzed_at',
        'question_bank_status',
        'question_bank_count',
        'type',
        'frequency',
        'difficulty',
        'notes',
        'is_active',
        'next_listen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'next_listen_at' => 'datetime',
            'analysis_payload' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listenLogs(): HasMany
    {
        return $this->hasMany(ListenLog::class);
    }

    public function listeningAssessments(): HasMany
    {
        return $this->hasMany(ListeningAssessment::class);
    }

    public function listeningQuestions(): HasMany
    {
        return $this->hasMany(ListeningQuestion::class)->orderBy('order');
    }

    public function isQuestionBankReady(): bool
    {
        return $this->question_bank_status === self::QUESTION_BANK_READY
            && $this->question_bank_count > 0;
    }

    public function isAnalysisReady(): bool
    {
        return $this->analysis_status === self::ANALYSIS_READY;
    }

    public function difficultyLabel(): string
    {
        return match ($this->difficulty) {
            self::DIFFICULTY_BEGINNER => 'Beginner',
            self::DIFFICULTY_ADVANCED => 'Advanced',
            default => 'Intermediate',
        };
    }

    public static function normalizeDifficulty(?string $value): string
    {
        return in_array($value, self::DIFFICULTIES, true)
            ? $value
            : self::DIFFICULTY_INTERMEDIATE;
    }
}
