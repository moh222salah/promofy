<?php

namespace App\Services\Crm;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Quotation;
use App\Services\Store\OrderService;
use App\Support\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    /** Allowed transitions. Only draft quotations are editable; everything sent is immutable. */
    public const WORKFLOW = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['viewed', 'approved', 'rejected', 'expired', 'cancelled'],
        'viewed' => ['approved', 'rejected', 'expired', 'cancelled'],
        'approved' => [], 'rejected' => [], 'expired' => [], 'cancelled' => [],
    ];

    public function decimals(string $currency): int
    {
        return (int) (Currency::where('code', $currency)->value('decimals') ?? 2);
    }

    /**
     * Replaces the items and recomputes every total server-side.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function saveItems(Quotation $quotation, array $items): void
    {
        $d = $this->decimals($quotation->currency_code);
        $quotation->items()->delete();
        $subtotal = 0;
        $discount = 0;

        foreach (array_values($items) as $i => $row) {
            $gross = (int) round(Money::minor($row['unit_price'], $d) * (float) $row['quantity']);
            $off = Money::percent($gross, $row['discount_percent'] ?? 0);
            $subtotal += $gross;
            $discount += $off;
            $quotation->items()->create([
                'service_id' => $row['service_id'] ?? null, 'name_ar' => $row['name_ar'], 'name_en' => $row['name_en'], 'description' => $row['description'] ?? null,
                'quantity' => $row['quantity'], 'unit_price' => Money::format(Money::minor($row['unit_price'], $d), $d), 'discount_percent' => $row['discount_percent'] ?? 0,
                'line_total' => Money::format($gross - $off, $d), 'sort_order' => $i,
            ]);
        }

        $net = $subtotal - $discount;
        $tax = Money::percent($net, $quotation->tax_rate);
        $quotation->forceFill([
            'subtotal' => Money::format($subtotal, $d), 'discount_total' => Money::format($discount, $d),
            'tax_total' => Money::format($tax, $d), 'total' => Money::format($net + $tax, $d),
        ])->save();
    }

    public function newNumber(Quotation $q): string
    {
        return 'QT-'.now()->format('y').'-'.str_pad((string) $q->id, 5, '0', STR_PAD_LEFT);
    }

    public function newToken(): string
    {
        return Str::random(40);
    }

    public function transition(Quotation $q, string $to, string $by, ?string $note = null): Quotation
    {
        if (! in_array($to, self::WORKFLOW[$q->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["Cannot move a quotation from {$q->status} to {$to}."]]);
        }

        return DB::transaction(function () use ($q, $to, $by, $note) {
            $q = Quotation::query()->lockForUpdate()->findOrFail($q->id);
            if (! in_array($to, self::WORKFLOW[$q->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => ['This quotation was already updated.']]);
            }
            $attrs = ['status' => $to];
            match ($to) {
                'sent' => $attrs['sent_at'] = now(),
                'viewed' => $attrs['viewed_at'] = now(),
                'approved', 'rejected' => $attrs += ['decided_at' => now(), 'decided_by' => $by, 'decision_note' => $note],
                default => null,
            };
            if ($to === 'sent') {
                if ($q->items()->count() === 0) {
                    throw ValidationException::withMessages(['items' => ['Add at least one line item before sending.']]);
                }
                if ($q->valid_until && $q->valid_until->isPast()) {
                    throw ValidationException::withMessages(['valid_until' => ['The validity date is in the past.']]);
                }
            }
            $q->forceFill($attrs)->save();
            AuditLogger::record("quotation.{$to}", $q, [], ['by' => $by, 'note' => $note]);

            return $q;
        });
    }

    /** Approved quotation → pending order (a quote-based order; payment is recorded later by finance). */
    public function convertToOrder(Quotation $q, int $userId): Order
    {
        if ($q->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only approved quotations can be converted.']]);
        }
        if ($q->order_id) {
            throw ValidationException::withMessages(['order' => ['This quotation was already converted.']]);
        }
        if (! $q->customer_email) {
            throw ValidationException::withMessages(['customer_email' => ['A customer e-mail is required to create an order.']]);
        }

        return DB::transaction(function () use ($q, $userId) {
            $order = new Order;
            $order->forceFill([
                'number' => 'TMP-'.Str::random(12), 'customer_name' => $q->customer_name, 'customer_email' => $q->customer_email, 'method' => 'quotation',
                'status' => 'pending', 'currency_code' => $q->currency_code, 'subtotal' => $q->subtotal, 'discount_total' => $q->discount_total,
                'tax_rate' => $q->tax_rate, 'tax_total' => $q->tax_total, 'total' => $q->total, 'customer_notes' => "From quotation {$q->number}",
                'lookup_token' => Str::random(40), 'assigned_to' => $q->assigned_to,
            ])->save();
            $order->forceFill(['number' => 'PRM-'.now()->format('y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();
            foreach ($q->items as $item) {
                $order->items()->create([
                    'sku' => 'QT-'.$q->id.'-'.$item->id, 'name_ar' => $item->name_ar, 'name_en' => $item->name_en, 'unit_price' => $item->unit_price,
                    'quantity' => max(1, (int) ceil($item->quantity)), 'line_total' => $item->line_total, 'is_digital' => false,
                ]);
            }
            app(OrderService::class)->log($order, null, 'pending', $userId, "Converted from quotation {$q->number}");
            $q->forceFill(['order_id' => $order->id])->save();
            AuditLogger::record('quotation.converted_to_order', $q, [], ['order' => $order->number]);

            return $order;
        });
    }

    public function expireOverdue(): int
    {
        $count = 0;
        Quotation::whereIn('status', ['sent', 'viewed'])->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString())->each(function (Quotation $q) use (&$count) {
            $this->transition($q, 'expired', 'system');
            $count++;
        });

        return $count;
    }
}
