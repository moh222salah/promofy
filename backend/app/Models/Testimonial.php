<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\Publishable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['author_name', 'author_role_ar', 'author_role_en', 'company', 'quote_ar', 'quote_en', 'rating', 'consent_given', 'is_featured', 'sort_order', 'status', 'published_at'])]
class Testimonial extends Model
{
    use Auditable, HasMedia, Publishable, SoftDeletes;

    protected function casts(): array
    {
        return ['consent_given' => 'boolean', 'is_featured' => 'boolean', 'published_at' => 'datetime'];
    }
}
