<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFile extends Model
{
    protected $guarded = [];

    /** Storage paths are never exposed through the API. */
    protected $hidden = ['disk', 'path'];
}
