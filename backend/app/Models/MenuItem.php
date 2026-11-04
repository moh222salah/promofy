<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['open_in_new_tab' => 'boolean', 'is_visible' => 'boolean'];
    }
}
