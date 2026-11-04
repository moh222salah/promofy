<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['customer_id', 'lead_id', 'customer_name', 'customer_company', 'customer_email', 'title', 'currency_code', 'valid_until', 'payment_terms', 'scope', 'notes', 'tax_rate', 'assigned_to'])]
class Quotation extends Model
{
    use Auditable, SoftDeletes;

    protected $hidden = ['public_token'];

    protected array $auditExclude = ['public_token', 'scope'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'sent_at' => 'datetime', 'viewed_at' => 'datetime', 'decided_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
