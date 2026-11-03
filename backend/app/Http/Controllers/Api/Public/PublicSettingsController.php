<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Currency;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;

class PublicSettingsController extends Controller
{
    /** Public site configuration: brand, contact, checkout methods, tracking ids (never secrets). */
    public function settings(SettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->publicSettings()])->header('Cache-Control', 'public, max-age=60');
    }

    /** Active countries (with phone codes and markets) and currencies for forms and pricing. */
    public function markets(): JsonResponse
    {
        return response()->json(['data' => [
            'countries' => Country::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'name_ar', 'name_en', 'phone_code', 'currency_code', 'flag', 'is_market', 'payment_methods', 'contact']),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'name_ar', 'name_en', 'symbol_ar', 'symbol_en', 'decimals']),
        ]])->header('Cache-Control', 'public, max-age=300');
    }
}
