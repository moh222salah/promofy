<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use Auditable, SoftDeletes;

    /** Status, totals and payment state change only through OrderService / PaymentService, never by mass assignment. */
    protected $guarded = ['*'];

    protected $hidden = ['lookup_token'];

    protected array $auditExclude = ['lookup_token', 'attribution'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'attribution' => 'array', 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->latest('id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
