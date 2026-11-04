<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'service_category_id', 'slug_ar', 'slug_en', 'name_ar', 'name_en', 'short_description_ar', 'short_description_en',
    'description_ar', 'description_en', 'features', 'deliverables', 'process_steps', 'faqs', 'duration_ar', 'duration_en',
    'pricing_type', 'price_from', 'price_to', 'currency_code', 'cta_type', 'cta_label_ar', 'cta_label_en', 'video_url',
    'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'seo_noindex', 'is_featured', 'sort_order',
    'status', 'published_at',
])]
class Service extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'features' => 'array', 'deliverables' => 'array', 'process_steps' => 'array', 'faqs' => 'array',
            'price_from' => 'decimal:2', 'price_to' => 'decimal:2', 'seo_noindex' => 'boolean',
            'is_featured' => 'boolean', 'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }
}
