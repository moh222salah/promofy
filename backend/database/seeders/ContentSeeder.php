<?php

namespace Database\Seeders;

use App\Models\ArticleCategory;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Slug;
use Illuminate\Database\Seeder;

/**
 * Initial structure only: the service catalogue from the brief, navigation, and empty system pages.
 * No descriptions, clients, results or testimonials are invented — editors write those in the dashboard.
 * Idempotent: running it again never overwrites what an administrator has edited.
 */
class ContentSeeder extends Seeder
{
    private const CATALOG = [
        ['Branding & Creative Strategy', 'الهوية والاستراتيجية الإبداعية', 'palette', [
            ['Brand Identity Design', 'تصميم الهوية البصرية'], ['Brand Positioning', 'تموضع العلامة التجارية'],
            ['Creative Direction', 'التوجيه الإبداعي'], ['Marketing Strategy', 'استراتيجية التسويق'],
            ['Brand Guidelines', 'دليل استخدام العلامة'], ['Brand Development', 'تطوير العلامة التجارية'],
            ['Strategic Marketing Consulting', 'استشارات التسويق الاستراتيجي'],
        ]],
        ['Production & Content Creation', 'الإنتاج وصناعة المحتوى', 'camera', [
            ['Product Photography', 'تصوير المنتجات'], ['Food Photography', 'تصوير الطعام'],
            ['Real Estate Photography', 'تصوير العقارات'], ['Interior and Architecture Photography', 'تصوير الديكور والعمارة'],
            ['Commercial Video Production', 'إنتاج الفيديو التجاري'], ['Social Media Content', 'محتوى وسائل التواصل الاجتماعي'],
            ['Reels Production', 'إنتاج الريلز'], ['Professional Studio Production', 'الإنتاج في الاستوديو الاحترافي'],
        ]],
        ['Performance Marketing', 'التسويق بالأداء', 'trending-up', [
            ['Meta Ads Management', 'إدارة إعلانات ميتا'], ['Google Ads Management', 'إدارة إعلانات جوجل'],
            ['Campaign Strategy', 'استراتيجية الحملات'], ['Campaign Optimization', 'تحسين الحملات'],
            ['Retargeting', 'إعادة الاستهداف'], ['Conversion Tracking', 'تتبع التحويلات'],
            ['Marketing Funnel Optimization', 'تحسين مسار التسويق والمبيعات'], ['Lead Generation Systems', 'أنظمة توليد العملاء المحتملين'],
        ]],
        ['Motion Graphics & Creative Campaigns', 'الموشن جرافيك والحملات الإبداعية', 'film', [
            ['2D Motion Graphics', 'موشن جرافيك ثنائي الأبعاد'], ['Animated Advertisements', 'إعلانات متحركة'],
            ['Explainer Videos', 'فيديوهات توضيحية'], ['Promotional Videos', 'فيديوهات ترويجية'],
            ['Creative Campaign Concepts', 'أفكار الحملات الإبداعية'], ['Motion Design', 'تصميم الحركة'],
            ['Advertising Visuals', 'مرئيات إعلانية'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::CATALOG as $i => [$en, $ar, $icon, $services]) {
            $category = ServiceCategory::firstOrCreate(['slug_en' => Slug::ascii($en)], [
                'slug_ar' => Slug::make($ar), 'name_en' => $en, 'name_ar' => $ar, 'icon' => $icon, 'sort_order' => $i + 1,
            ]);
            foreach ($services as $j => [$sEn, $sAr]) {
                Service::firstOrCreate(['slug_en' => Slug::ascii($sEn)], [
                    'service_category_id' => $category->id, 'slug_ar' => Slug::make($sAr), 'name_en' => $sEn, 'name_ar' => $sAr,
                    'pricing_type' => 'quote', 'cta_type' => 'quote', 'sort_order' => ($i + 1) * 100 + $j,
                    'status' => 'published', 'published_at' => now(),
                ]);
            }
        }

        foreach ([['Brand Strategy', 'استراتيجية العلامة'], ['Production Insights', 'رؤى الإنتاج'], ['Performance Marketing', 'التسويق بالأداء'], ['Marketing Education', 'تعليم التسويق']] as $i => [$en, $ar]) {
            ArticleCategory::firstOrCreate(['slug_en' => Slug::ascii($en)], ['slug_ar' => Slug::make($ar), 'name_en' => $en, 'name_ar' => $ar, 'sort_order' => $i + 1]);
        }

        Page::firstOrCreate(['slug_en' => 'about'], [
            'slug_ar' => 'من-نحن', 'title_en' => 'About', 'title_ar' => 'من نحن', 'is_system' => true, 'status' => 'draft',
        ]);

        $nav = [
            ['Home', 'الرئيسية', '/'], ['About', 'من نحن', '/about'], ['Services', 'الخدمات', '/services'],
            ['Portfolio', 'أعمالنا', '/portfolio'], ['Case Studies', 'دراسات الحالة', '/case-studies'], ['Store', 'المتجر', '/store'],
            ['Insights', 'المقالات', '/insights'], ['Contact', 'تواصل معنا', '/contact'], ['Start a Project', 'ابدأ مشروعك', '/start-a-project'],
        ];
        foreach (['header' => $nav, 'footer' => array_slice($nav, 1, 7)] as $location => $items) {
            $menu = Menu::firstOrCreate(['location' => $location], ['name' => ucfirst($location)]);
            if ($menu->items()->exists()) {
                continue;
            }
            foreach ($items as $i => [$en, $ar, $url]) {
                $menu->items()->create(['label_en' => $en, 'label_ar' => $ar, 'url' => $url, 'sort_order' => $i]);
            }
        }
    }
}
