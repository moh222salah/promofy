<?php

/*
|--------------------------------------------------------------------------
| Settings schema
|--------------------------------------------------------------------------
| group => ['permission' => manage ability, 'keys' => key => definition].
| type: string | text | bool | int | url | email | color | bilingual | json | secret
| public: exposed to the public website through /api/v1/public/settings.
*/

return [

    'site' => [
        'permission' => 'settings.manage',
        'keys' => [
            'name' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => 'PROMOFY', 'en' => 'PROMOFY']],
            'tagline' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => 'لا نسوّق فقط. نصنع علامات تهم.', 'en' => "We Don't Just Market. We Make Brands Matter."]],
            'description' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => '', 'en' => '']],
            'logo_url' => ['type' => 'string', 'public' => true],
            'favicon_url' => ['type' => 'string', 'public' => true],
            'default_locale' => ['type' => 'string', 'public' => true, 'default' => 'ar'],
            'footer_text' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => '', 'en' => '']],
            'color_primary' => ['type' => 'color', 'public' => true, 'default' => '#102B5C'],
            'color_secondary' => ['type' => 'color', 'public' => true, 'default' => '#164E9B'],
            'color_accent' => ['type' => 'color', 'public' => true, 'default' => '#0E41FE'],
            'color_light' => ['type' => 'color', 'public' => true, 'default' => '#EAF1FC'],
            'color_dark' => ['type' => 'color', 'public' => true, 'default' => '#081426'],
            'maintenance_mode' => ['type' => 'bool', 'public' => true, 'default' => false],
        ],
    ],

    'contact' => [
        'permission' => 'settings.manage',
        'keys' => [
            'email' => ['type' => 'email', 'public' => true],
            'phone' => ['type' => 'string', 'public' => true],
            'whatsapp_number' => ['type' => 'string', 'public' => true],
            'address' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => '', 'en' => '']],
            'map_url' => ['type' => 'url', 'public' => true],
            'business_hours' => ['type' => 'json', 'public' => true, 'default' => []],
            'social' => ['type' => 'json', 'public' => true, 'default' => ['instagram' => 'https://www.instagram.com/promofy___']],
        ],
    ],

    'checkout' => [
        'permission' => 'payments.manage',
        'keys' => [
            'online_payment_enabled' => ['type' => 'bool', 'public' => true, 'default' => false],
            'whatsapp_order_enabled' => ['type' => 'bool', 'public' => true, 'default' => true],
            'guest_checkout_enabled' => ['type' => 'bool', 'public' => true, 'default' => true],
            'default_currency' => ['type' => 'string', 'public' => true, 'default' => 'EGP'],
            'tax_rate_percent' => ['type' => 'decimal', 'public' => true, 'default' => 0],
            'tax_label' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => 'ضريبة القيمة المضافة', 'en' => 'VAT']],
            'download_link_hours' => ['type' => 'int', 'public' => false, 'default' => 48],
            'download_max_attempts' => ['type' => 'int', 'public' => false, 'default' => 5],
        ],
    ],

    'marketing' => [
        'permission' => 'marketing.manage',
        'keys' => [
            'ga4_measurement_id' => ['type' => 'string', 'public' => true],
            'gtm_container_id' => ['type' => 'string', 'public' => true],
            'meta_pixel_id' => ['type' => 'string', 'public' => true],
            'search_console_verification' => ['type' => 'string', 'public' => true],
            'consent_banner_enabled' => ['type' => 'bool', 'public' => true, 'default' => true],
        ],
    ],

    'seo' => [
        'permission' => 'seo.edit',
        'keys' => [
            'default_title' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => 'PROMOFY | وكالة تسويق إبداعي ونمو رقمي', 'en' => 'PROMOFY | Creative Marketing & Digital Growth Agency']],
            'default_description' => ['type' => 'bilingual', 'public' => true, 'default' => ['ar' => '', 'en' => '']],
            'og_image_url' => ['type' => 'string', 'public' => true],
            'robots_index' => ['type' => 'bool', 'public' => false, 'default' => true],
        ],
    ],

    'notifications' => [
        'permission' => 'notifications.manage',
        'keys' => [
            'recipients' => ['type' => 'json', 'public' => false, 'default' => []],
            'channels' => ['type' => 'json', 'public' => false, 'default' => ['database' => true, 'mail' => true, 'whatsapp' => false]],
            'templates' => ['type' => 'json', 'public' => false, 'default' => []],
        ],
    ],

    'security' => [
        'permission' => 'system.manage',
        'keys' => [
            'require_two_factor_for_staff' => ['type' => 'bool', 'public' => false, 'default' => false],
            'max_login_attempts' => ['type' => 'int', 'public' => false, 'default' => 5],
            'lockout_minutes' => ['type' => 'int', 'public' => false, 'default' => 15],
        ],
    ],
];
