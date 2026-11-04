<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentRefund;
use App\Services\Settings\SettingsService;
use App\Services\Store\OrderService;
use App\Support\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private readonly SettingsService $settings, private readonly OrderService $orders) {}

    public function gateway(?string $key = null): ?PaymentGateway
    {
        $key ??= (string) $this->settings->get('payments', 'gateway', 'none');

        return match ($key) {
            'generic' => app(SignedWebhookGateway::class),
            default => null,
        };
    }

    /** Creates a pending payment for an online order. It never marks anything as paid. */
    public function initiate(Order $order): array
    {
        $gateway = $this->gateway() ?? throw ValidationException::withMessages(['method' => ['No payment gateway is configured.']]);

        return DB::transaction(function () use ($order, $gateway) {
            $payment = new Payment;
            $payment->forceFill([
                'order_id' => $order->id, 'provider' => $gateway->key(), 'provider_reference' => 'PAY-'.strtoupper(Str::random(14)),
                'amount' => $order->total, 'currency_code' => $order->currency_code, 'status' => 'pending',
            ])->save();

            return ['payment_id' => $payment->id] + $gateway->createCheckout($order, $payment);
        });
    }

    /**
     * Webhook entry point. Order of checks: signature → idempotency → payment match → amount/currency match → state change.
     *
     * @return array{http: int, outcome: string}
     */
    public function handleWebhook(string $gatewayKey, string $rawBody, array $headers): array
    {
        $gateway = $this->gateway($gatewayKey);
        if ($gateway === null) {
            return ['http' => 404, 'outcome' => 'unknown_gateway'];
        }
        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];

        if (! $gateway->verifySignature($rawBody, $headers)) {
            PaymentEvent::create(['provider' => $gateway->key(), 'event_id' => 'invalid-'.Str::random(24), 'signature_valid' => false, 'payload' => ['ip_only' => true], 'outcome' => 'rejected_signature', 'processed_at' => now()]);

            return ['http' => 401, 'outcome' => 'invalid_signature'];
        }

        $parsed = $gateway->parse($payload);
        if ($parsed['event_id'] === '' || $parsed['reference'] === '') {
            return ['http' => 422, 'outcome' => 'malformed'];
        }

        try {
            $event = PaymentEvent::create(['provider' => $gateway->key(), 'event_id' => $parsed['event_id'], 'signature_valid' => true, 'payload' => $payload]);
        } catch (UniqueConstraintViolationException) {
            return ['http' => 200, 'outcome' => 'duplicate'];
        }

        $outcome = DB::transaction(function () use ($gateway, $parsed) {
            $payment = Payment::query()->where('provider', $gateway->key())->where('provider_reference', $parsed['reference'])->lockForUpdate()->first();
            if (! $payment) {
                return 'unknown_payment';
            }
            if ($payment->status !== 'pending') {
                return 'already_final';
            }
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $decimals = (int) (\App\Models\Currency::where('code', $payment->currency_code)->value('decimals') ?? 2);

            if ($parsed['status'] !== 'succeeded') {
                $payment->forceFill(['status' => 'failed', 'note' => 'Reported failed by provider'])->save();
                AuditLogger::record('payment.failed', $payment, [], ['order' => $order->number], [], null);

                return 'failed';
            }
            if (Money::minor($parsed['amount'], $decimals) !== Money::minor($payment->amount, $decimals) || $parsed['currency'] !== $payment->currency_code) {
                $payment->forceFill(['status' => 'failed', 'note' => 'Amount or currency mismatch'])->save();
                AuditLogger::record('payment.mismatch', $payment, [], ['expected' => $payment->amount.' '.$payment->currency_code, 'got' => $parsed['amount'].' '.$parsed['currency']]);

                return 'mismatch';
            }

            $payment->forceFill(['status' => 'succeeded', 'verified_at' => now()])->save();
            if (in_array($order->status, ['awaiting_payment', 'pending'], true)) {
                $this->orders->applyPaymentState($order, 'paid', null, 'Payment confirmed by provider');
            }
            AuditLogger::record('payment.confirmed', $payment, [], ['order' => $order->number, 'amount' => $payment->amount], [], null);

            return 'confirmed';
        });

        $event->forceFill(['outcome' => $outcome, 'processed_at' => now()])->save();

        return ['http' => 200, 'outcome' => $outcome];
    }

    /** Finance staff records money received outside a gateway (bank transfer, cash). Always audited. */
    public function recordManual(Order $order, string $amount, ?string $note, int $userId): Payment
    {
        if (in_array($order->status, ['cancelled', 'refunded'], true) || $order->paid_at !== null) {
            throw ValidationException::withMessages(['order' => ['This order cannot accept a manual payment.']]);
        }
        $decimals = (int) (\App\Models\Currency::where('code', $order->currency_code)->value('decimals') ?? 2);
        if (Money::minor($amount, $decimals) !== Money::minor($order->total, $decimals)) {
            throw ValidationException::withMessages(['amount' => ['The amount must equal the order total ('.$order->total.' '.$order->currency_code.').']]);
        }

        return DB::transaction(function () use ($order, $amount, $note, $userId) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payment = new Payment;
            $payment->forceFill([
                'order_id' => $order->id, 'provider' => 'manual', 'provider_reference' => 'MAN-'.strtoupper(Str::random(12)), 'amount' => $amount,
                'currency_code' => $order->currency_code, 'status' => 'succeeded', 'recorded_by' => $userId, 'note' => $note,
            ])->save();
            $this->orders->applyPaymentState($order, 'paid', $userId, 'Manual payment recorded');
            AuditLogger::record('payment.manual_recorded', $payment, [], ['order' => $order->number, 'amount' => $amount, 'note' => $note]);

            return $payment;
        });
    }

    public function refund(Payment $payment, string $amount, string $reason, int $userId): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $userId) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
                throw ValidationException::withMessages(['payment' => ['Only successful payments can be refunded.']]);
            }
            $decimals = (int) (\App\Models\Currency::where('code', $payment->currency_code)->value('decimals') ?? 2);
            $remaining = Money::minor($payment->amount, $decimals) - Money::minor($payment->refunded_total, $decimals);
            $want = Money::minor($amount, $decimals);
            if ($want <= 0 || $want > $remaining) {
                throw ValidationException::withMessages(['amount' => ['Refund must be greater than 0 and not exceed the remaining '.Money::format($remaining, $decimals).'.']]);
            }

            PaymentRefund::create(['payment_id' => $payment->id, 'amount' => Money::format($want, $decimals), 'reason' => $reason, 'user_id' => $userId]);
            $total = Money::minor($payment->refunded_total, $decimals) + $want;
            $full = $total === Money::minor($payment->amount, $decimals);
            $payment->forceFill(['refunded_total' => Money::format($total, $decimals), 'status' => $full ? 'refunded' : 'partially_refunded'])->save();

            if ($full) {
                $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
                $this->orders->applyPaymentState($order, 'refunded', $userId, 'Fully refunded: '.$reason);
            }
            AuditLogger::record('payment.refunded', $payment, [], ['amount' => Money::format($want, $decimals), 'reason' => $reason, 'full' => $full]);

            return $payment;
        });
    }
}
