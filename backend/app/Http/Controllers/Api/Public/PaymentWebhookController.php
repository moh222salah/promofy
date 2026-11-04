<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $payments, string $gateway): JsonResponse
    {
        $result = $payments->handleWebhook($gateway, $request->getContent(), $request->headers->all());

        return response()->json(['outcome' => $result['outcome']], $result['http']);
    }
}
