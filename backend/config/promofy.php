<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform
    |--------------------------------------------------------------------------
    */

    'version' => '0.1.0',

    'brand' => [
        'name' => 'PROMOFY',
        'tagline' => "We Don't Just Market. We Make Brands Matter.",
        'domain' => 'promofy.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    | Arabic (RTL) and English (LTR). The default language is configurable
    | by administrators at runtime; these values are only the boot defaults.
    */

    'locales' => ['ar', 'en'],
    'default_locale' => env('APP_LOCALE', 'ar'),

    'security' => [
        'max_login_attempts' => (int) env('AUTH_MAX_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
        'two_factor_challenge_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | SPA
    |--------------------------------------------------------------------------
    */

    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

];
