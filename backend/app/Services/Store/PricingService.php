<?php

namespace App\Services\Store;

use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Settings\SettingsService;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Server-side pricing: prices always come from the database, never from the client.
 * A basket is priced in one currency; mixing currencies is rejected (no implicit exchange rates).
 */
class PricingService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  iterable<array{product: Product, variant: ?ProductVariant, quantity: int, options?: array}>  $lines
     * @return array{currency: string, decimals: int, lines: list<array<string, mixed>>, subtotal: string, discount_total: string, tax_rate: string, tax_total: string, total: string}
     */
    public function price(iterable $lines): array
    {
        $currency = null;
        $out = [];
        $subtotal = 0;
        $discount = 0;

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $variant = $line['variant'] ?? null;
            $currency ??= $product->currency_code;
            if ($product->currency_code !== $currency) {
                throw ValidationException::withMessages(['cart' => ['All items in one order must use the same currency.']]);
            }
            $decimals = $this->decimals($currency);
            $regular = Money::minor($variant?->price ?? $product->price, $decimals);
            $unit = Money::minor($product->effectivePrice($variant), $decimals);
            $qty = max(1, (int) $line['quantity']);

            $subtotal += $regular * $qty;
            $discount += ($regular - $unit) * $qty;
            $out[] = [
                'product_id' => $product->id, 'product_variant_id' => $variant?->id,
                'sku' => $variant?->sku ?? $product->sku, 'name_ar' => $product->name_ar.($variant ? ' — '.$variant->name_ar : ''),
                'name_en' => $product->name_en.($variant ? ' — '.$variant->name_en : ''),
                'options' => $line['options'] ?? ($variant?->option_values), 'unit_price' => Money::format($unit, $decimals),
                'quantity' => $qty, 'line_total' => Money::format($unit * $qty, $decimals), 'is_digital' => $product->isDigital(),
            ];
        }

        $currency ??= (string) $this->settings->get('checkout', 'default_currency', 'EGP');
        $decimals = $this->decimals($currency);
        $net = $subtotal - $discount;
        $rate = (string) $this->settings->get('checkout', 'tax_rate_percent', 0);
        $tax = Money::percent($net, $rate);

        return [
            'currency' => $currency, 'decimals' => $decimals, 'lines' => $out,
            'subtotal' => Money::format($subtotal, $decimals), 'discount_total' => Money::format($discount, $decimals),
            'tax_rate' => number_format((float) $rate, 2, '.', ''), 'tax_total' => Money::format($tax, $decimals),
            'total' => Money::format($net + $tax, $decimals),
        ];
    }

    private function decimals(string $code): int
    {
        return (int) (Currency::query()->where('code', $code)->value('decimals') ?? 2);
    }
}
