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

    'uploads' => [
        'max_image_kb' => (int) env('UPLOAD_MAX_IMAGE_KB', 8192),
        'max_video_kb' => (int) env('UPLOAD_MAX_VIDEO_KB', 204800),
        'max_document_kb' => (int) env('UPLOAD_MAX_DOCUMENT_KB', 20480),
        'image_mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'video_mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
        'document_mimes' => [
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/zip' => 'zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        ],
        'image_variants' => ['thumb' => 400, 'medium' => 1000, 'large' => 1920],
    ],

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
