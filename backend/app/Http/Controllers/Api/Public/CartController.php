<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Store\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Server-side cart identified by an unguessable token (header X-Cart-Token). Prices are always computed server-side. */
class CartController extends Controller
{
    public function __construct(private readonly PricingService $pricing) {}

    public static function resolve(Request $request, bool $create = false): ?Cart
    {
        $token = (string) ($request->header('X-Cart-Token') ?? $request->input('cart_token', ''));
        $cart = $token !== '' ? Cart::where('token', $token)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first() : null;
        if (! $cart && $create) {
            $cart = Cart::create(['token' => Str::random(40), 'expires_at' => now()->addDays(30)]);
        }

        return $cart;
    }

    /** @return list<array{product: Product, variant: mixed, quantity: int, options: ?array}> */
    public static function lines(Cart $cart): array
    {
        $out = [];
        foreach ($cart->items()->with(['product' => fn ($q) => $q->published()->with('files'), 'variant'])->get() as $item) {
            if (! $item->product) {
                continue; // product was unpublished since being added
            }
            $out[] = ['product' => $item->product, 'variant' => $item->variant, 'quantity' => $item->quantity, 'options' => $item->selected_options, 'item_id' => $item->id];
        }

        return $out;
    }

    private function view(Cart $cart): JsonResponse
    {
        $lines = self::lines($cart->refresh());
        try {
            $priced = $this->pricing->price($lines);
        } catch (ValidationException $e) {
            $priced = null;
        }
        $items = [];
        foreach ($lines as $i => $line) {
            $items[] = ['id' => $line['item_id'], 'product' => StoreController::present($line['product']->loadMissing('media')), 'variant_id' => $line['variant']?->id, 'quantity' => $line['quantity']]
                + ($priced ? ['unit_price' => $priced['lines'][$i]['unit_price'], 'line_total' => $priced['lines'][$i]['line_total']] : []);
        }

        return response()->json(['data' => ['token' => $cart->token, 'items' => $items, 'totals' => $priced ? \Illuminate\Support\Arr::except($priced, ['lines']) : null, 'mixed_currency' => $priced === null]]);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->view(self::resolve($request, true));
    }

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'], 'variant_id' => ['nullable', 'integer'], 'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'options' => ['nullable', 'array', 'max:10'], 'options.*' => ['string', 'max:100'],
        ]);
        $product = Product::query()->published()->with('variants')->findOrFail($data['product_id']);
        $variant = null;
        if ($product->has_variants) {
            $variant = $product->variants->where('is_active', true)->firstWhere('id', $data['variant_id'] ?? 0)
                ?? throw ValidationException::withMessages(['variant_id' => ['Choose an available variant.']]);
        } elseif (! empty($data['variant_id'])) {
            throw ValidationException::withMessages(['variant_id' => ['This product has no variants.']]);
        }

        $cart = self::resolve($request, true);
        $qty = (int) ($data['quantity'] ?? 1);
        $item = $cart->items()->where('product_id', $product->id)->where('product_variant_id', $variant?->id)->first();
        $newQty = ($item?->quantity ?? 0) + $qty;
        $this->assertStock($product, $variant, $newQty);

        // one currency per cart
        $other = $cart->items()->with('product')->get()->first(fn ($i) => $i->product && $i->product->currency_code !== $product->currency_code);
        if ($other) {
            throw ValidationException::withMessages(['product_id' => ['Your cart contains items in another currency. Check out or clear it first.']]);
        }

        $item ? $item->update(['quantity' => $newQty]) : $cart->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => $qty, 'selected_options' => $data['options'] ?? null]);
        $cart->forceFill(['expires_at' => now()->addDays(30)])->save();

        return $this->view($cart);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:100']]);
        $cart = self::resolve($request) ?? abort(404);
        $row = $cart->items()->with(['product', 'variant'])->findOrFail($item);
        $this->assertStock($row->product, $row->variant, $data['quantity']);
        $row->update(['quantity' => $data['quantity']]);

        return $this->view($cart);
    }

    public function remove(Request $request, string $item): JsonResponse
    {
        $cart = self::resolve($request) ?? abort(404);
        $cart->items()->findOrFail($item)->delete();

        return $this->view($cart);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = self::resolve($request) ?? abort(404);
        $cart->items()->delete();

        return $this->view($cart);
    }

    private function assertStock(Product $product, $variant, int $qty): void
    {
        if (! $product->track_stock || $product->allow_backorder) {
            return;
        }
        $available = $variant ? $variant->stock_quantity : $product->stock_quantity;
        if ($qty > $available) {
            throw ValidationException::withMessages(['quantity' => ["Only {$available} left in stock."]]);
        }
    }
}
