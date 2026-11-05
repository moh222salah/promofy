<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer portal: a signed-in customer sees only their own orders / quotations. */
class AccountController extends Controller
{
    private function ownOrders(Request $request): Builder
    {
        $user = $request->user();

        // Orders placed while signed in, plus guest orders under the same email once that email is verified.
        return Order::query()->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->email_verified_at) {
                $q->orWhere('customer_email', $user->email);
            }
        });
    }

    public function orders(Request $request): JsonResponse
    {
        $page = $this->ownOrders($request)->latest()->paginate(min((int) $request->query('per_page', 15), 50), ['number', 'status', 'method', 'currency_code', 'total', 'created_at', 'paid_at']);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function order(Request $request, string $number): JsonResponse
    {
        $order = $this->ownOrders($request)->where('number', $number)->with(['items', 'history'])->firstOrFail();

        return response()->json(['data' => $order->only(['number', 'status', 'method', 'currency_code', 'subtotal', 'discount_total', 'tax_rate', 'tax_total', 'total', 'created_at', 'paid_at'])
            + ['items' => $order->items->map->only(['sku', 'name_ar', 'name_en', 'options', 'unit_price', 'quantity', 'line_total', 'is_digital'])->values(),
                'history' => $order->history->map->only(['to_status', 'created_at'])->values(),
                // lets the portal reuse the token-based tracking / download endpoints for the owner's own order
                'lookup_token' => $order->lookup_token]]);
    }

    public function quotations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->email_verified_at) {
            return response()->json(['data' => []]);
        }
        $rows = Quotation::where('customer_email', $user->email)->whereNotIn('status', ['draft'])->latest()->get(['number', 'title', 'status', 'currency_code', 'total', 'valid_until', 'public_token']);

        return response()->json(['data' => $rows->map(fn ($q) => $q->only(['number', 'title', 'status', 'currency_code', 'total', 'valid_until']) + ['link' => url("/api/v1/public/quotations/{$q->number}?token={$q->public_token}")])->values()]);
    }
}
