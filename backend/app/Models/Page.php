<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'parent_id', 'slug_ar', 'slug_en', 'title_ar', 'title_en', 'content_ar', 'content_en', 'template',
    'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'seo_noindex', 'is_featured', 'sort_order',
    'status', 'published_at',
])]
class Page extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected array $auditExclude = ['content_ar', 'content_en'];

    protected function casts(): array
    {
        return ['seo_noindex' => 'boolean', 'is_featured' => 'boolean', 'is_system' => 'boolean', 'published_at' => 'datetime'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest('id');
    }
}
