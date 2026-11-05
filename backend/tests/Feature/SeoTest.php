<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\Seo\SeoService;
use App\Services\Settings\SettingsService;
use Database\Seeders\ContentSeeder;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
        $this->seed(ContentSeeder::class);
        config(['promofy.frontend_url' => 'https://promofy.test']);
    }

    public function test_sitemap_lists_only_live_indexable_content_with_hreflang(): void
    {
        $live = Service::published()->firstOrFail();
        $hidden = Service::published()->where('id', '!=', $live->id)->firstOrFail();
        $hidden->forceFill(['status' => 'draft'])->save();
        $noindex = Service::published()->where('id', '!=', $live->id)->firstOrFail();
        $noindex->forceFill(['seo_noindex' => true])->save();

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertStringContainsString('https://promofy.test/en/services/'.$live->slug_en, $xml);
        $this->assertStringContainsString('hreflang="ar" href="https://promofy.test/ar/services/'.$live->slug_ar.'"', $xml);
        $this->assertStringContainsString('hreflang="x-default"', $xml);
        $this->assertStringNotContainsString($hidden->slug_en, $xml);
        $this->assertStringNotContainsString($noindex->slug_en, $xml);
        $this->assertStringContainsString('https://promofy.test/ar/contact', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_robots_blocks_private_areas_and_respects_the_index_switch(): void
    {
        $txt = $this->get('/robots.txt')->assertOk()->getContent();
        $this->assertStringContainsString('Disallow: /admin', $txt);
        $this->assertStringContainsString('Disallow: /en/checkout', $txt);
        $this->assertStringContainsString('/sitemap.xml', $txt);

        app(SettingsService::class)->update('seo', ['robots_index' => false]);
        $this->assertStringContainsString("Disallow: /\n", $this->get('/robots.txt')->getContent());
        $svc = Service::published()->firstOrFail();
        $this->getJson('/api/v1/public/seo?type=service&slug='.$svc->slug_en)->assertOk()->assertJsonPath('data.robots', 'noindex,nofollow');
    }

    public function test_every_content_type_resolves_and_sitemap_points_to_the_public_site(): void
    {
        $this->assertStringContainsString('Sitemap: https://promofy.test/sitemap.xml', $this->get('/robots.txt')->getContent());
        foreach (SeoService::TYPES as $type => [$model, $segment]) {
            $rec = $model::query()->published()->first();
            if (! $rec) {
                continue;                                  // nothing seeded for this type
            }
            $d = $this->getJson("/api/v1/public/seo?type=$type&locale=ar&slug=".$rec->slug_ar)->assertOk()->json('data');
            $path = '/ar/'.($segment ? "$segment/" : '').$rec->slug_ar;
            $this->assertSame('https://promofy.test'.$path, $d['canonical'], $type);
            $this->assertNotEmpty($d['title'], $type);
            $this->assertNotEmpty($d['json_ld'], $type);
        }
        // a Product and an Article exercise their specific JSON-LD branches
        $product = \App\Models\Product::query()->forceCreate(['type' => 'digital_download', 'sku' => 'SEO1', 'slug_ar' => 'tmp-ar', 'slug_en' => 'tmp-en', 'name_ar' => 'منتج', 'name_en' => 'Prod', 'price' => 100, 'currency_code' => 'EGP', 'status' => 'published', 'published_at' => now()->subDay()]);
        $ld = collect($this->getJson('/api/v1/public/seo?type=product&locale=en&slug=tmp-en')->assertOk()->json('data.json_ld'))->firstWhere('@type', 'Product');
        $this->assertSame('EGP', $ld['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $ld['offers']['availability']);
        $this->assertSame('https://promofy.test/en/store/tmp-en', $ld['url']);
        $product->forceFill(['seo_noindex' => true])->save();
        $this->assertSame('noindex,nofollow', $this->getJson('/api/v1/public/seo?type=product&slug=tmp-en')->json('data.robots'));
        $this->assertStringNotContainsString('tmp-en', $this->get('/sitemap.xml')->getContent());
    }

    public function test_meta_endpoint_returns_canonical_alternates_and_json_ld(): void
    {
        $svc = Service::published()->firstOrFail();
        $svc->forceFill(['seo_title_en' => 'Custom SEO title'])->save();
        $d = $this->getJson('/api/v1/public/seo?type=service&locale=en&slug='.$svc->slug_en)->assertOk()->json('data');
        $this->assertStringStartsWith('Custom SEO title | ', $d['title']);
        $this->assertSame('https://promofy.test/en/services/'.$svc->slug_en, $d['canonical']);
        $this->assertSame('https://promofy.test/ar/services/'.$svc->slug_ar, $d['alternates']['ar']);
        $this->assertSame('index,follow', $d['robots']);
        $this->assertSame('Service', collect($d['json_ld'])->pluck('@type')->intersect(['Service'])->first());
        $this->assertContains('BreadcrumbList', collect($d['json_ld'])->pluck('@type')->all());

        $svc->forceFill(['status' => 'draft'])->save();
        $this->getJson('/api/v1/public/seo?type=service&slug='.$svc->slug_en)->assertNotFound();
        $this->getJson('/api/v1/public/seo?type=bogus&slug=x')->assertUnprocessable();
    }
}
