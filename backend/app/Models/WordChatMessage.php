<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WordChatMessage extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'content',
        'cursor_run_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runAsUserMessage(): HasOne
    {
        return $this->hasOne(WordChatRun::class, 'user_message_id');
    }
}
