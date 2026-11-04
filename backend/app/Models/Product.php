<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_category_id', 'type', 'sku', 'slug_ar', 'slug_en', 'name_ar', 'name_en', 'short_description_ar', 'short_description_en',
    'description_ar', 'description_en', 'price', 'discount_price', 'currency_code', 'options', 'has_variants', 'track_stock',
    'stock_quantity', 'low_stock_threshold', 'allow_backorder', 'allow_online_payment', 'allow_whatsapp_order', 'subscription_interval',
    'video_url', 'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'seo_noindex', 'is_featured',
    'sort_order', 'status', 'published_at',
])]
class Product extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    public const DIGITAL_TYPES = ['digital_download', 'template'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'discount_price' => 'decimal:2', 'options' => 'array', 'has_variants' => 'boolean',
            'track_stock' => 'boolean', 'allow_backorder' => 'boolean', 'allow_online_payment' => 'boolean',
            'allow_whatsapp_order' => 'boolean', 'seo_noindex' => 'boolean', 'is_featured' => 'boolean', 'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProductFile::class);
    }

    public function isDigital(): bool
    {
        return in_array($this->type, self::DIGITAL_TYPES, true) || $this->files()->exists();
    }

    /** Effective unit price (discount applied when lower than the regular price). */
    public function effectivePrice(?ProductVariant $variant = null): string
    {
        $price = $variant?->price ?? $this->price;
        $discount = $variant?->price !== null ? $variant->discount_price : $this->discount_price;

        return ($discount !== null && (float) $discount < (float) $price) ? (string) $discount : (string) $price;
    }
}
