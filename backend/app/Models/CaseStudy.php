<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'slug_ar', 'slug_en', 'title_ar', 'title_en', 'client_name', 'client_approved', 'industry_ar', 'industry_en',
    'overview_ar', 'overview_en', 'challenge_ar', 'challenge_en', 'approach_ar', 'approach_en', 'execution_ar', 'execution_en',
    'campaign_ar', 'campaign_en', 'results', 'results_verified', 'seo_title_ar', 'seo_title_en', 'seo_description_ar',
    'seo_description_en', 'seo_noindex', 'is_featured', 'sort_order', 'status', 'published_at',
])]
class CaseStudy extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'client_approved' => 'boolean', 'results' => 'array', 'results_verified' => 'boolean',
            'seo_noindex' => 'boolean', 'is_featured' => 'boolean', 'published_at' => 'datetime',
        ];
    }
}
