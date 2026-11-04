<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'description', 'due_at', 'priority', 'assigned_to', 'taskable_type', 'taskable_id'])]
class Task extends Model
{
    use Auditable;

    protected static function booted(): void
    {
        $recalc = function (Task $task) {
            if ($task->taskable_type === 'project' && $task->taskable_id) {
                app(\App\Services\Crm\ProjectService::class)->recalculate((int) $task->taskable_id);
            }
        };
        static::saved($recalc);
        static::deleted($recalc);
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
