<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['base_currency', 'quote_currency', 'rate', 'source', 'effective_at'])]
class ExchangeRate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'effective_at' => 'datetime'];
    }

    /** The latest configured rate for a pair, or null when none was ever set. */
    public static function latest(string $base, string $quote): ?self
    {
        return static::query()->where('base_currency', $base)->where('quote_currency', $quote)
            ->where('effective_at', '<=', now())->orderByDesc('effective_at')->orderByDesc('id')->first();
    }
}
