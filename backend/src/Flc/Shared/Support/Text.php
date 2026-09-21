<?php

namespace Flc\Shared\Support;

/**
 * Framework-free string helpers for Domain / Application layers.
 * Infrastructure may use Illuminate\Support\Str instead.
 */
final class Text
{
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }
}
