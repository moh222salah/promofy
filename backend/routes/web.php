<?php

use App\Http\Controllers\Api\Public\SeoController;
use Illuminate\Support\Facades\Route;

// Placeholder until the SPA shell (public website + admin) is wired in a later phase.
Route::get('/', function () {
    return response()->json([
        'name' => 'PROMOFY API',
        'health' => url('/api/v1/health'),
    ]);
});

Route::get('/sitemap.xml', [SeoController::class, 'sitemap']);
Route::get('/robots.txt', [SeoController::class, 'robots']);
