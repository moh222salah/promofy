<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sku', 'name_ar', 'name_en', 'option_values', 'price', 'discount_price', 'stock_quantity', 'is_active', 'sort_order'])]
class ProductVariant extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['option_values' => 'array', 'price' => 'decimal:2', 'discount_price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
