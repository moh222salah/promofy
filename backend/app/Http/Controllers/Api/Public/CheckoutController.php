<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\DownloadToken;
use App\Models\Order;
use App\Services\Payments\PaymentService;
use App\Services\Settings\SettingsService;
use App\Services\Store\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly PaymentService $payments, private readonly SettingsService $settings) {}

    /** Which ordering methods the storefront may offer right now. */
    public function methods(): JsonResponse
    {
        return response()->json(['data' => [
            'online' => (bool) $this->settings->get('checkout', 'online_payment_enabled', false) && $this->settings->get('payments', 'gateway', 'none') !== 'none',
            'whatsapp' => (bool) $this->settings->get('checkout', 'whatsapp_order_enabled', true) && (string) $this->settings->get('contact', 'whatsapp_number', '') !== '',
            'guest_checkout' => (bool) $this->settings->get('checkout', 'guest_checkout_enabled', true),
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['online', 'whatsapp'])],
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{6,32}$/'],
            'country' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'attribution' => ['nullable', 'array'], 'attribution.*' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user('sanctum');
        if (! $user && ! $this->settings->get('checkout', 'guest_checkout_enabled', true)) {
            throw ValidationException::withMessages(['email' => ['Please sign in to place an order.']]);
        }

        $cart = CartController::resolve($request) ?? throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        $lines = CartController::lines($cart);
        if ($lines === []) {
            throw ValidationException::withMessages(['cart' => ['Your cart is empty.']]);
        }

        $order = $this->orders->place($lines, $data + ['country' => $data['country']], $data['method'], $user?->id, array_slice($data['attribution'] ?? [], 0, 12));
        DB::transaction(fn () => $cart->items()->delete());

        $response = ['order' => $this->present($order), 'lookup_token' => $order->lookup_token];
        if ($data['method'] === 'online') {
            $response['payment'] = $this->payments->initiate($order);
        } else {
            $response['whatsapp'] = $this->whatsapp($order);
        }

        return response()->json(['data' => $response], 201);
    }

    private function whatsapp(Order $order): array
    {
        $number = preg_replace('/\D+/', '', (string) $this->settings->get('contact', 'whatsapp_number', ''));
        $lines = ["PROMOFY — Order {$order->number}"];
        foreach ($order->items as $item) {
            $opts = $item->options ? ' ['.collect($item->options)->flatten()->implode(', ').']' : '';
            $lines[] = "- {$item->name_en}{$opts} × {$item->quantity} = {$item->line_total} {$order->currency_code}";
        }
        $lines[] = "Total: {$order->total} {$order->currency_code}";
        $lines[] = "Customer: {$order->customer_name} ({$order->customer_email})";
        $text = implode("\n", $lines);

        return ['url' => $number !== '' ? "https://wa.me/{$number}?text=".rawurlencode($text) : null, 'message' => $text];
    }

    private function present(Order $order): array
    {
        return $order->loadMissing('items')->only(['number', 'status', 'method', 'currency_code', 'subtotal', 'discount_total', 'tax_rate', 'tax_total', 'total', 'customer_name', 'customer_email', 'created_at', 'paid_at'])
            + ['items' => $order->items->map->only(['sku', 'name_ar', 'name_en', 'options', 'unit_price', 'quantity', 'line_total', 'is_digital'])->values()];
    }

    private function findByToken(Request $request, string $number): Order
    {
        $token = (string) $request->header('X-Order-Token', $request->query('token', ''));
        $order = Order::where('number', $number)->firstOrFail();
        abort_unless($token !== '' && hash_equals($order->lookup_token, $token), 404);

        return $order;
    }

    /** Order tracking for the buyer (needs the private lookup token returned at checkout). */
    public function track(Request $request, string $number): JsonResponse
    {
        $order = $this->findByToken($request, $number);

        return response()->json(['data' => $this->present($order) + ['history' => $order->history->map->only(['to_status', 'created_at'])->values()]]);
    }

    /** Issues expiring, attempt-limited download links for a paid order's digital files. */
    public function issueDownloads(Request $request, string $number): JsonResponse
    {
        $order = $this->findByToken($request, $number);
        abort_unless($order->paid_at !== null && ! in_array($order->status, ['refunded', 'cancelled'], true), 403, 'Downloads are available after payment is confirmed.');

        $hours = (int) $this->settings->get('checkout', 'download_link_hours', 48);
        $max = (int) $this->settings->get('checkout', 'download_max_attempts', 5);
        $links = [];
        foreach ($order->items()->where('is_digital', true)->whereNotNull('product_id')->get() as $item) {
            foreach (\App\Models\ProductFile::where('product_id', $item->product_id)->get() as $file) {
                $raw = Str::random(48);
                DownloadToken::create(['order_item_id' => $item->id, 'product_file_id' => $file->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addHours($hours), 'max_attempts' => $max]);
                $links[] = ['name' => $file->original_name, 'label' => $file->label, 'size' => $file->size, 'url' => url("/api/v1/public/downloads/{$raw}"), 'expires_at' => now()->addHours($hours)];
            }
        }

        return response()->json(['data' => $links]);
    }

    public function download(string $token)
    {
        $record = DownloadToken::where('token_hash', hash('sha256', $token))->first();
        abort_if(! $record || $record->expires_at->isPast() || $record->attempts >= $record->max_attempts, 404);

        $item = \App\Models\OrderItem::findOrFail($record->order_item_id);
        $order = Order::findOrFail($item->order_id);
        abort_unless($order->paid_at !== null && ! in_array($order->status, ['refunded', 'cancelled'], true), 403);

        $file = \App\Models\ProductFile::findOrFail($record->product_file_id);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);
        $record->increment('attempts');

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }
}
