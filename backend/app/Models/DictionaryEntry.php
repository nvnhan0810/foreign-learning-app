<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DictionaryEntry extends Model
{
    protected $fillable = [
        'word',
        'phonetic',
        'audio_url',
        'source',
        'is_curated',
        'save_count',
    ];

    protected function casts(): array
    {
        return [
            'is_curated' => 'boolean',
            'save_count' => 'integer',
        ];
    }

    public function meanings(): HasMany
    {
        return $this->hasMany(DictionaryMeaning::class)->orderBy('position');
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(DictionarySynonym::class)->orderBy('position');
    }

    public function antonyms(): HasMany
    {
        return $this->hasMany(DictionaryAntonym::class)->orderBy('position');
    }

    public function vocabularies(): HasMany
    {
        return $this->hasMany(Vocabulary::class);
    }
}
