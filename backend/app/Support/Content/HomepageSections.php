<?php

namespace App\Support\Content;

/** Section catalogue of the homepage builder. Copy is configurable; no statistics are ever pre-filled. */
final class HomepageSections
{
    public const TYPES = [
        'hero', 'trust', 'featured_services', 'selected_projects', 'featured_case_studies', 'growth_system',
        'why_promofy', 'process', 'testimonials', 'featured_products', 'insights', 'contact_cta',
    ];

    /** Section types whose content is picked from other modules by id. */
    public const PICKERS = [
        'featured_services' => 'services', 'selected_projects' => 'portfolio_items', 'featured_case_studies' => 'case_studies',
        'testimonials' => 'testimonials', 'featured_products' => 'products', 'insights' => 'articles',
    ];

    /** @return list<array<string, mixed>> */
    public static function defaults(): array
    {
        $s = fn (string $type, bool $enabled = true, array $extra = []) => array_merge([
            'id' => $type, 'type' => $type, 'enabled' => $enabled,
            'title_ar' => null, 'title_en' => null, 'subtitle_ar' => null, 'subtitle_en' => null, 'settings' => (object) [],
        ], $extra);

        return [
            $s('hero', true, [
                'title_ar' => 'لا نسوّق فقط. نحن نجعل العلامات التجارية مهمة.',
                'title_en' => "We Don't Just Market. We Make Brands Matter.",
                'settings' => ['media_id' => null, 'video_media_id' => null, 'buttons' => [
                    ['label_ar' => 'ابدأ مشروعك', 'label_en' => 'Start a Project', 'url' => '/start-a-project', 'style' => 'primary'],
                    ['label_ar' => 'استكشف خدماتنا', 'label_en' => 'Explore Services', 'url' => '/services', 'style' => 'secondary'],
                ]],
            ]),
            $s('trust', false), $s('featured_services'), $s('selected_projects'), $s('featured_case_studies', false),
            $s('growth_system'), $s('why_promofy'), $s('process'), $s('testimonials', false),
            $s('featured_products', false), $s('insights', false), $s('contact_cta'),
        ];
    }
}
