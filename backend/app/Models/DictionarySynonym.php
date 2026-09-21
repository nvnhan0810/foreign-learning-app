<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DictionarySynonym extends Model
{
    protected $fillable = [
        'dictionary_entry_id',
        'dictionary_meaning_id',
        'term',
        'position',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DictionaryEntry::class, 'dictionary_entry_id');
    }

    public function meaning(): BelongsTo
    {
        return $this->belongsTo(DictionaryMeaning::class, 'dictionary_meaning_id');
    }
}
