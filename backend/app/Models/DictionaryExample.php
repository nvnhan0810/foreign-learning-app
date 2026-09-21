<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DictionaryExample extends Model
{
    protected $fillable = [
        'dictionary_meaning_id',
        'example',
        'position',
    ];

    public function meaning(): BelongsTo
    {
        return $this->belongsTo(DictionaryMeaning::class, 'dictionary_meaning_id');
    }
}
