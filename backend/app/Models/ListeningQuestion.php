<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListeningQuestion extends Model
{
    public const TYPE_MCQ = 'mcq';

    public const TYPE_FILL_BLANK = 'fill_blank';

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_COMPREHENSION = 'comprehension';

    protected $fillable = [
        'media_item_id',
        'order',
        'question_type',
        'prompt',
        'options',
        'correct_answer',
        'explanation',
        'audio_start_seconds',
        'audio_end_seconds',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }
}
