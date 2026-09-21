<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedEmailEntry extends Model
{
    protected $fillable = [
        'pattern',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function setPatternAttribute(string $value): void
    {
        $this->attributes['pattern'] = strtolower(trim($value));
    }
}
