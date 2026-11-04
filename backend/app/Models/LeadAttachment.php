<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadAttachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['disk', 'path'];
}
