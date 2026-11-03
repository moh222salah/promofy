<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name_ar', 'name_en', 'phone_code', 'currency_code', 'flag', 'is_active', 'is_market', 'payment_methods', 'contact', 'sort_order'])]
class Country extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_market' => 'boolean', 'payment_methods' => 'array', 'contact' => 'array'];
    }
}
