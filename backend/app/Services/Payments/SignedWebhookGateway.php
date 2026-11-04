<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Settings\SettingsService;

/**
 * Provider-neutral gateway: a hosted checkout page that calls back with an HMAC-SHA256 signed JSON body
 * ("X-Signature: <hex hmac of raw body with the shared secret>"). Concrete providers (Paymob, Fawry, Geidea…)
 * are implemented as further PaymentGateway classes once merchant credentials and the provider's current
 * signing rules are verified; until then no provider is claimed to be integrated.
 */
class SignedWebhookGateway implements PaymentGateway
{
    public function __construct(private readonly SettingsService $settings) {}

    public function key(): string
    {
        return 'generic';
    }

    public function createCheckout(Order $order, Payment $payment): array
    {
        $base = (string) $this->settings->get('payments', 'gateway_checkout_url', '');
        $url = $base !== '' ? $base.(str_contains($base, '?') ? '&' : '?').http_build_query(['reference' => $payment->provider_reference, 'amount' => $payment->amount, 'currency' => $payment->currency_code]) : null;

        return ['redirect_url' => $url, 'reference' => $payment->provider_reference];
    }

    public function verifySignature(string $rawBody, array $headers): bool
    {
        $secret = (string) $this->settings->get('payments', 'gateway_webhook_secret', '');
        $given = (string) ($headers['x-signature'][0] ?? $headers['x-signature'] ?? '');
        if ($secret === '' || $given === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($given));
    }

    public function parse(array $payload): array
    {
        return [
            'event_id' => (string) ($payload['event_id'] ?? ''), 'reference' => (string) ($payload['reference'] ?? ''),
            'status' => ($payload['status'] ?? '') === 'succeeded' ? 'succeeded' : 'failed',
            'amount' => (string) ($payload['amount'] ?? '0'), 'currency' => strtoupper((string) ($payload['currency'] ?? '')),
        ];
    }
}
