<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\CountryController;
use App\Http\Controllers\Api\Admin\CurrencyController;
use App\Http\Controllers\Api\Admin\ExchangeRateController;
use App\Http\Controllers\Api\Admin\LoginHistoryController;
use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\ProfileController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\Public\PublicSettingsController;
use App\Support\Crud\CrudRoutes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PROMOFY REST API — /api/v1
|--------------------------------------------------------------------------
| Every route below is automatically prefixed with "api/v1" (see bootstrap/app.php).
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'promofy-api',
        'version' => config('promofy.version'),
        'time' => now()->toIso8601String(),
    ]);
})->name('api.health');

// ---------------------------------------------------------------- Public
Route::prefix('public')->group(function () {
    Route::get('settings', [PublicSettingsController::class, 'settings']);
    Route::get('markets', [PublicSettingsController::class, 'markets']);
});

// ---------------------------------------------------------------- Authentication
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('two-factor-challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:two-factor');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:forms');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:forms');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:forms');
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::put('password', [PasswordController::class, 'update'])->middleware('throttle:forms');
        Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:forms');
        Route::get('login-history', [ProfileController::class, 'loginHistory']);

        Route::post('two-factor/enable', [TwoFactorController::class, 'enable']);
        Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('two-factor', [TwoFactorController::class, 'disable']);
        Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);

        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions', [SessionController::class, 'destroyOthers']);
        Route::delete('sessions/{handle}', [SessionController::class, 'destroy']);
    });
});

// ---------------------------------------------------------------- Admin (staff only)
Route::prefix('admin')->middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('permissions', [PermissionController::class, 'index']);

    Route::post('users/{id}/unlock', [UserController::class, 'unlock'])->whereNumber('id');
    Route::post('users/{id}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->whereNumber('id');
    Route::post('users/{id}/send-password-reset', [UserController::class, 'sendPasswordReset'])->whereNumber('id');
    Route::delete('users/{id}/sessions', [UserController::class, 'revokeSessions'])->whereNumber('id');
    Route::get('users/{id}/login-history', [UserController::class, 'loginHistory'])->whereNumber('id');
    CrudRoutes::register('users', UserController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'export', 'bulk']]);

    CrudRoutes::register('roles', RoleController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options']]);
    CrudRoutes::register('audit-logs', AuditLogController::class, ['only' => ['index', 'show', 'export']]);
    CrudRoutes::register('login-history', LoginHistoryController::class, ['only' => ['index', 'show']]);

    Route::get('settings', [SettingController::class, 'index']);
    Route::get('settings/{group}', [SettingController::class, 'show']);
    Route::put('settings/{group}', [SettingController::class, 'update']);
    CrudRoutes::register('countries', CountryController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder']]);
    CrudRoutes::register('currencies', CurrencyController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder']]);
    CrudRoutes::register('exchange-rates', ExchangeRateController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy']]);

    Route::post('media/upload', [MediaController::class, 'upload']);
    Route::post('media/{id}/replace', [MediaController::class, 'replace'])->whereNumber('id');
    Route::get('media/{id}/usages', [MediaController::class, 'usages'])->whereNumber('id');
    CrudRoutes::register('media', MediaController::class, ['only' => ['index', 'show', 'update', 'destroy', 'options']]);
});
