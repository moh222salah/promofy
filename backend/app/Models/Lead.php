<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'company', 'email', 'phone', 'country_code', 'industry', 'source', 'service_id', 'budget', 'timeline', 'contact_method', 'description', 'priority', 'assigned_to'])]
class Lead extends Model
{
    use Auditable, SoftDeletes;

    public const STATUSES = ['new', 'contacted', 'qualified', 'proposal_sent', 'negotiation', 'won', 'lost'];

    public const SOURCES = ['instagram', 'facebook', 'google', 'website', 'whatsapp', 'referral', 'direct', 'other'];

    protected array $auditExclude = ['attribution', 'description'];

    protected function casts(): array
    {
        return ['attribution' => 'array', 'last_followup_at' => 'datetime', 'next_followup_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class);
    }
}
