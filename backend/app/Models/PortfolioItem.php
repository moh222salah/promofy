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
    'service_category_id', 'slug_ar', 'slug_en', 'title_ar', 'title_en', 'client_name', 'client_approved', 'summary_ar', 'summary_en',
    'brief_ar', 'brief_en', 'video_url', 'completed_at', 'tags', 'seo_title_ar', 'seo_title_en', 'seo_description_ar',
    'seo_description_en', 'seo_noindex', 'is_featured', 'sort_order', 'status', 'published_at',
])]
class PortfolioItem extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'client_approved' => 'boolean', 'tags' => 'array', 'completed_at' => 'date', 'seo_noindex' => 'boolean',
            'is_featured' => 'boolean', 'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }
}
