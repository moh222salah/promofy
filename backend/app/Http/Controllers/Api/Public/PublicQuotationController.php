<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Crm\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Customer-facing quotation review. Access requires the private token from the shared link. */
class PublicQuotationController extends Controller
{
    private function find(Request $request, string $number): Quotation
    {
        $token = (string) $request->header('X-Quotation-Token', $request->query('token', ''));
        $q = Quotation::where('number', $number)->whereNot('status', 'draft')->firstOrFail();
        abort_unless($token !== '' && hash_equals($q->public_token, $token), 404);

        return $q;
    }

    private function present(Quotation $q): array
    {
        return $q->load('items')->only(['number', 'title', 'status', 'currency_code', 'customer_name', 'customer_company', 'valid_until', 'payment_terms', 'scope', 'notes',
            'subtotal', 'discount_total', 'tax_rate', 'tax_total', 'total', 'sent_at', 'decided_at'])
            + ['items' => $q->items->map->only(['name_ar', 'name_en', 'description', 'quantity', 'unit_price', 'discount_percent', 'line_total'])->values()];
    }

    public function show(Request $request, QuotationService $quotes, string $number): JsonResponse
    {
        $q = $this->find($request, $number);
        if ($q->status === 'sent') {
            $q = $quotes->transition($q, 'viewed', 'customer');
        }

        return response()->json(['data' => $this->present($q)]);
    }

    public function decide(Request $request, QuotationService $quotes, string $number): JsonResponse
    {
        $q = $this->find($request, $number);
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'note' => ['nullable', 'string', 'max:255', 'required_if:decision,rejected']]);
        if ($q->valid_until && $q->valid_until->isPast()) {
            return response()->json(['message' => 'This quotation has expired.'], 422);
        }
        $q = $quotes->transition($q, $data['decision'], 'customer', $data['note'] ?? null);

        return response()->json(['data' => $this->present($q)]);
    }
}
