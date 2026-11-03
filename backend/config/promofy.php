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

    /*
    |--------------------------------------------------------------------------
    | SPA
    |--------------------------------------------------------------------------
    */

    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

];
