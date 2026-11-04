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
    'article_category_id', 'slug_ar', 'slug_en', 'title_ar', 'title_en', 'excerpt_ar', 'excerpt_en', 'body_ar', 'body_en',
    'reading_minutes', 'video_url', 'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'seo_noindex',
    'is_featured', 'sort_order', 'status', 'published_at',
])]
class Article extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return ['seo_noindex' => 'boolean', 'is_featured' => 'boolean', 'published_at' => 'datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'article_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
