<?php

use App\Http\Controllers\Api\Admin\ArticleCategoryController;
use App\Http\Controllers\Api\Admin\ArticleController;
use App\Http\Controllers\Api\Admin\CaseStudyController;
use App\Http\Controllers\Api\Admin\ContactMessageController;
use App\Http\Controllers\Api\Admin\HomepageController;
use App\Http\Controllers\Api\Admin\NavigationController;
use App\Http\Controllers\Api\Admin\PageController;
use App\Http\Controllers\Api\Admin\PortfolioItemController;
use App\Http\Controllers\Api\Admin\ServiceCategoryController;
use App\Http\Controllers\Api\Admin\ServiceController;
use App\Http\Controllers\Api\Admin\TeamMemberController;
use App\Http\Controllers\Api\Admin\TestimonialController;
use App\Http\Controllers\Api\Public\PublicContentController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\PaymentController;
use App\Http\Controllers\Api\Admin\ProductCategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Public\CartController;
use App\Http\Controllers\Api\Public\CheckoutController;
use App\Http\Controllers\Api\Public\PaymentWebhookController;
use App\Http\Controllers\Api\Public\StoreController;
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

    Route::get('homepage', [PublicContentController::class, 'homepage']);
    Route::get('navigation', [PublicContentController::class, 'navigation']);
    Route::get('service-categories', [PublicContentController::class, 'serviceCategories']);
    Route::get('services', [PublicContentController::class, 'services']);
    Route::get('services/{slug}', [PublicContentController::class, 'service']);
    Route::get('portfolio', [PublicContentController::class, 'portfolio']);
    Route::get('portfolio/{slug}', [PublicContentController::class, 'portfolioItem']);
    Route::get('case-studies', [PublicContentController::class, 'caseStudies']);
    Route::get('case-studies/{slug}', [PublicContentController::class, 'caseStudy']);
    Route::get('article-categories', [PublicContentController::class, 'articleCategories']);
    Route::get('articles', [PublicContentController::class, 'articles']);
    Route::get('articles/{slug}', [PublicContentController::class, 'article']);
    Route::get('pages/{slug}', [PublicContentController::class, 'page']);
    Route::get('testimonials', [PublicContentController::class, 'testimonials']);
    Route::get('team', [PublicContentController::class, 'team']);
    Route::post('contact', [PublicContentController::class, 'contact'])->middleware('throttle:forms');

    // Store
    Route::get('store/categories', [StoreController::class, 'categories']);
    Route::get('store/products', [StoreController::class, 'index']);
    Route::get('store/products/{slug}', [StoreController::class, 'show']);
    Route::get('cart', [CartController::class, 'show']);
    Route::post('cart/items', [CartController::class, 'add'])->middleware('throttle:forms');
    Route::patch('cart/items/{item}', [CartController::class, 'update'])->whereNumber('item');
    Route::delete('cart/items/{item}', [CartController::class, 'remove'])->whereNumber('item');
    Route::delete('cart', [CartController::class, 'clear']);
    Route::get('checkout/methods', [CheckoutController::class, 'methods']);
    Route::post('checkout', [CheckoutController::class, 'checkout'])->middleware('throttle:forms');
    Route::get('orders/{number}', [CheckoutController::class, 'track'])->middleware('throttle:forms');
    Route::post('orders/{number}/downloads', [CheckoutController::class, 'issueDownloads'])->middleware('throttle:forms');
    Route::get('downloads/{token}', [CheckoutController::class, 'download'])->middleware('throttle:forms');
    Route::post('payments/webhook/{gateway}', [PaymentWebhookController::class, 'handle'])->middleware('throttle:120,1');
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

    // Content management
    CrudRoutes::register('service-categories', ServiceCategoryController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder']]);
    CrudRoutes::register('services', ServiceController::class);
    CrudRoutes::register('portfolio', PortfolioItemController::class, ['except' => ['export']]);
    CrudRoutes::register('case-studies', CaseStudyController::class, ['except' => ['export']]);
    CrudRoutes::register('article-categories', ArticleCategoryController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder']]);
    CrudRoutes::register('articles', ArticleController::class, ['except' => ['export', 'reorder']]);
    Route::get('pages/{id}/revisions', [PageController::class, 'revisions'])->whereNumber('id');
    Route::post('pages/{id}/revisions/{revision}/restore', [PageController::class, 'restore'])->whereNumber(['id', 'revision']);
    CrudRoutes::register('pages', PageController::class, ['except' => ['export', 'reorder']]);
    CrudRoutes::register('testimonials', TestimonialController::class, ['except' => ['export']]);
    CrudRoutes::register('team', TeamMemberController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder', 'duplicate']]);
    CrudRoutes::register('contact-messages', ContactMessageController::class, ['only' => ['index', 'show', 'update', 'destroy', 'export', 'bulk', 'options']]);

    Route::get('homepage', [HomepageController::class, 'draft']);
    Route::put('homepage', [HomepageController::class, 'saveDraft']);
    Route::get('homepage/preview', [HomepageController::class, 'preview']);
    Route::post('homepage/publish', [HomepageController::class, 'publish']);
    Route::get('homepage/history', [HomepageController::class, 'history']);
    Route::post('homepage/history/{id}/restore', [HomepageController::class, 'restore'])->whereNumber('id');

    Route::get('navigation', [NavigationController::class, 'index']);
    Route::put('navigation/{location}', [NavigationController::class, 'update']);

    // Store
    CrudRoutes::register('product-categories', ProductCategoryController::class, ['only' => ['index', 'show', 'store', 'update', 'destroy', 'options', 'reorder']]);
    Route::get('products/low-stock', [ProductController::class, 'lowStock']);
    Route::post('products/{id}/stock', [ProductController::class, 'adjustStock'])->whereNumber('id');
    Route::get('products/{id}/movements', [ProductController::class, 'movements'])->whereNumber('id');
    Route::post('products/{id}/files', [ProductController::class, 'uploadFile'])->whereNumber('id');
    Route::delete('products/{id}/files/{file}', [ProductController::class, 'deleteFile'])->whereNumber(['id', 'file']);
    CrudRoutes::register('products', ProductController::class, ['except' => ['reorder']]);

    Route::post('orders/{id}/status', [OrderController::class, 'transition'])->whereNumber('id');
    Route::post('orders/{id}/assign', [OrderController::class, 'assign'])->whereNumber('id');
    Route::post('orders/{id}/notes', [OrderController::class, 'addNote'])->whereNumber('id');
    Route::post('orders/{id}/payments', [OrderController::class, 'recordPayment'])->whereNumber('id');
    Route::get('orders/{id}/invoice', [OrderController::class, 'invoice'])->whereNumber('id');
    Route::post('payments/{payment}/refund', [OrderController::class, 'refund'])->whereNumber('payment');
    CrudRoutes::register('orders', OrderController::class, ['only' => ['index', 'show', 'export']]);
    CrudRoutes::register('payments', PaymentController::class, ['only' => ['index', 'show', 'export']]);
});
