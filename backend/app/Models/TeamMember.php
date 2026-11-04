<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name_ar', 'name_en', 'title_ar', 'title_en', 'bio_ar', 'bio_en', 'social', 'sort_order', 'is_active'])]
class TeamMember extends Model
{
    use Auditable, HasMedia, SoftDeletes;

    protected function casts(): array
    {
        return ['social' => 'array', 'is_active' => 'boolean'];
    }
}
