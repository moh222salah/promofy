<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'email', 'phone', 'subject', 'message', 'status'])]
class ContactMessage extends Model
{
    use Auditable, SoftDeletes;

    protected array $auditExclude = ['message', 'user_agent'];

    protected function casts(): array
    {
        return ['attribution' => 'array'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
