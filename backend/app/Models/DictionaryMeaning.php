<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DictionaryMeaning extends Model
{
    protected $fillable = [
        'dictionary_entry_id',
        'part_of_speech',
        'definition',
        'position',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(DictionaryEntry::class, 'dictionary_entry_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(DictionaryExample::class)->orderBy('position');
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(DictionarySynonym::class)->orderBy('position');
    }

    public function antonyms(): HasMany
    {
        return $this->hasMany(DictionaryAntonym::class)->orderBy('position');
    }
}
