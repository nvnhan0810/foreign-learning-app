<?php

namespace App\Models;

use App\Models\ListeningQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListeningAssessment extends Model
{
    public const TYPE_QUIZ = 'quiz';

    public const TYPE_TEST = 'test';

    public const TYPE_EXAM = 'exam';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'media_item_id',
        'user_id',
        'type',
        'title',
        'description',
        'question_count',
        'question_ids',
        'time_limit_minutes',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'question_ids' => 'array',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ListeningQuestion::class)->orderBy('order');
    }

    /**
     * @return Collection<int, ListeningQuestion>
     */
    public function sessionQuestions(): Collection
    {
        $ids = array_map('intval', $this->question_ids ?? []);

        if ($ids === []) {
            return $this->questions;
        }

        $questions = ListeningQuestion::query()
            ->where('media_item_id', $this->media_item_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return new Collection(
            collect($ids)
                ->map(fn (int $id) => $questions->get($id))
                ->filter()
                ->values()
                ->all()
        );
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ListeningAttempt::class);
    }
}
