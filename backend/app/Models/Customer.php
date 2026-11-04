<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'company', 'email', 'phone', 'country_code', 'industry', 'source', 'notes'])]
class Customer extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return ['attribution' => 'array'];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
