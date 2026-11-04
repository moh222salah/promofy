<?php

namespace App\Support;

use Illuminate\Support\Str;

final class Slug
{
    /** URL-safe slug that keeps Arabic letters. */
    public static function make(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';

        return trim($text, '-');
    }

    public static function ascii(string $text): string
    {
        return Str::slug($text);
    }

    /** @param  class-string<\Illuminate\Database\Eloquent\Model>  $model */
    public static function unique(string $model, string $column, string $base, ?int $ignoreId = null): string
    {
        $base = $base !== '' ? $base : 'item';
        $slug = $base;
        $i = 2;
        while ($model::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->when(
            in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true),
            fn ($q) => $q->withTrashed()
        )->where($column, $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
