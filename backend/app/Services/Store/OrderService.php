<?php

namespace App\Services\Store;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Settings\SettingsService;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /** from => allowed next statuses. "paid" and "refunded" are reached only through PaymentService. */
    public const WORKFLOW = [
        'pending' => ['awaiting_payment', 'processing', 'cancelled'],
        'awaiting_payment' => ['pending', 'cancelled'],
        'paid' => ['processing', 'in_progress', 'completed'],
        'processing' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function __construct(
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<array{product: Product, variant: ?ProductVariant, quantity: int, options?: array}>  $lines
     * @param  array<string, mixed>  $customer
     */
    public function place(array $lines, array $customer, string $method, ?int $userId, array $attribution = []): Order
    {
        $this->assertMethodAllowed($method, $lines);

        return DB::transaction(function () use ($lines, $customer, $method, $userId, $attribution) {
            $priced = $this->pricing->price($lines);
            abort_if($priced['lines'] === [], 422, 'The cart is empty.');

            $order = new Order;
            $order->forceFill([
                'number' => 'TMP-'.Str::random(12), 'user_id' => $userId, 'customer_name' => $customer['name'], 'customer_email' => $customer['email'],
                'customer_phone' => $customer['phone'] ?? null, 'country_code' => isset($customer['country']) ? strtoupper($customer['country']) : null,
                'method' => $method, 'status' => $method === 'online' ? 'awaiting_payment' : 'pending', 'currency_code' => $priced['currency'],
                'subtotal' => $priced['subtotal'], 'discount_total' => $priced['discount_total'], 'tax_rate' => $priced['tax_rate'],
                'tax_total' => $priced['tax_total'], 'total' => $priced['total'], 'customer_notes' => $customer['notes'] ?? null,
                'attribution' => $attribution ?: null, 'lookup_token' => Str::random(40),
            ])->save();
            $order->forceFill(['number' => 'PRM-'.now()->format('y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();

            foreach ($priced['lines'] as $i => $row) {
                $order->items()->create($row);
                $line = $lines[$i];
                if ($line['product']->track_stock) {
                    $this->inventory->change($line['product'], $line['variant'], -$row['quantity'], 'order', 'order', $order->id);
                }
            }
            $this->log($order, null, $order->status, $userId, 'Order placed ('.$method.')');
            AuditLogger::record('order.placed', $order, [], ['number' => $order->number, 'total' => $order->total, 'method' => $method]);

            DB::afterCommit(fn () => \App\Services\Notifications\NotificationService::send('order.placed', ['number' => $order->number, 'total' => $order->total, 'currency' => $order->currency_code]));

            return $order->load('items');
        });
    }

    /** Staff-driven status change within the workflow. */
    public function transition(Order $order, string $to, ?int $userId, ?string $note = null): Order
    {
        $allowed = self::WORKFLOW[$order->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ["Cannot move an order from {$order->status} to {$to}."]]);
        }
        if ($to === 'cancelled' && $order->paid_at !== null) {
            throw ValidationException::withMessages(['status' => ['A paid order must be refunded, not cancelled.']]);
        }

        return DB::transaction(function () use ($order, $to, $userId, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;
            $order->forceFill(['status' => $to])->save();
            if ($to === 'cancelled') {
                $this->restock($order, 'cancellation', $userId);
            }
            $this->log($order, $from, $to, $userId, $note);

            return $order;
        });
    }

    /** Used by PaymentService inside its own transaction. */
    public function applyPaymentState(Order $order, string $to, ?int $userId, string $note): void
    {
        $from = $order->status;
        $attrs = ['status' => $to];
        if ($to === 'paid') {
            $attrs['paid_at'] = now();
        }
        $order->forceFill($attrs)->save();
        if ($to === 'refunded') {
            $this->restock($order, 'refund', $userId);
        }
        $this->log($order, $from, $to, $userId, $note);
    }

    public function log(Order $order, ?string $from, string $to, ?int $userId, ?string $note = null): void
    {
        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $userId, 'note' => $note]);
    }

    private function restock(Order $order, string $reason, ?int $userId): void
    {
        foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
            $product = Product::find($item->product_id);
            if ($product && $product->track_stock) {
                $variant = $item->product_variant_id ? ProductVariant::find($item->product_variant_id) : null;
                $this->inventory->change($product, $variant, $item->quantity, $reason, 'order', $order->id, null, $userId);
            }
        }
    }

    private function assertMethodAllowed(string $method, array $lines): void
    {
        $enabled = $method === 'online'
            ? (bool) $this->settings->get('checkout', 'online_payment_enabled', false) && $this->settings->get('payments', 'gateway', 'none') !== 'none'
            : (bool) $this->settings->get('checkout', 'whatsapp_order_enabled', true);
        if (! $enabled) {
            throw ValidationException::withMessages(['method' => ['This ordering method is currently unavailable.']]);
        }
        foreach ($lines as $line) {
            $flag = $method === 'online' ? 'allow_online_payment' : 'allow_whatsapp_order';
            if (! $line['product']->{$flag}) {
                throw ValidationException::withMessages(['method' => ["{$line['product']->name_en} cannot be ordered this way."]]);
            }
        }
    }
}
