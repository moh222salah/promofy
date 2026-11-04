<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'is_digital' => 'boolean'];
    }
}
