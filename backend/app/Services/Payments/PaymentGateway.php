<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;

interface PaymentGateway
{
    public function key(): string;

    /** @return array{redirect_url: ?string, reference: ?string} */
    public function createCheckout(Order $order, Payment $payment): array;

    /** True only when the callback authenticity can be cryptographically verified. */
    public function verifySignature(string $rawBody, array $headers): bool;

    /** @return array{event_id: string, reference: string, status: string, amount: string, currency: string} status: succeeded|failed */
    public function parse(array $payload): array;
}
