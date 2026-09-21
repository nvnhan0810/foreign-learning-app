<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VocabularyLearningInsight extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'vocabulary_id',
        'word',
        'insight_type',
        'question',
        'content',
        'source_message_id',
        'metadata',
        'quiz_eligible',
        'times_used_in_quiz',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'quiz_eligible' => 'boolean',
            'times_used_in_quiz' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vocabulary(): BelongsTo
    {
        return $this->belongsTo(Vocabulary::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(WordChatMessage::class, 'source_message_id');
    }
}
