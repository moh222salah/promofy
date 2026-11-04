<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'customer_id', 'order_id', 'quotation_id', 'lead_id', 'type', 'priority', 'start_date', 'due_date', 'description', 'scope', 'manager_id'])]
class Project extends Model
{
    use Auditable, HasMedia, SoftDeletes;

    public const STATUSES = ['planning', 'in_progress', 'under_review', 'on_hold', 'completed', 'cancelled'];

    protected array $auditExclude = ['scope'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withPivot('role');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('sort_order')->orderBy('due_date');
    }
}
