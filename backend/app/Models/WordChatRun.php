<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordChatRun extends Model
{
    protected $fillable = [
        'user_id',
        'word_chat_agent_id',
        'cursor_agent_id',
        'cursor_run_id',
        'user_message_id',
        'assistant_message_id',
        'status',
        'assistant_content',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(WordChatAgent::class, 'word_chat_agent_id');
    }

    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(WordChatMessage::class, 'user_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(WordChatMessage::class, 'assistant_message_id');
    }
}
