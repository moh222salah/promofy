<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_ar', 'name_en', 'symbol_ar', 'symbol_en', 'decimals', 'is_active', 'sort_order'])]
class Currency extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'decimals' => 'integer'];
    }
}
