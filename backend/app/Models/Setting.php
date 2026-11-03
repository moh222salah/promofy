<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group', 'key', 'value', 'is_secret'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }
}
