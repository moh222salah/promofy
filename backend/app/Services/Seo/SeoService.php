<?php

namespace App\Services\Seo;

use App\Models\Article;
use App\Models\CaseStudy;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Eloquent\Model;

/** Public URLs, hreflang alternates, meta tags, JSON-LD, sitemap and robots — all from published database content. */
class SeoService
{
    public const LOCALES = ['ar', 'en'];

    /** type => [model, path segment on the public site] */
    public const TYPES = [
        'service' => [Service::class, 'services'],
        'portfolio' => [PortfolioItem::class, 'portfolio'],
        'case-study' => [CaseStudy::class, 'case-studies'],
        'article' => [Article::class, 'insights'],
        'product' => [Product::class, 'store'],
        'page' => [Page::class, ''],
    ];

    /** Static listing pages that exist as routes of the public site. */
    private const STATIC = ['', 'services', 'portfolio', 'case-studies', 'store', 'insights', 'contact', 'start-a-project'];

    public function __construct(private SettingsService $settings) {}

    public function base(): string
    {
        return rtrim((string) config('promofy.frontend_url'), '/');
    }

    public function url(string $locale, string $segment = '', ?string $slug = null): string
    {
        return $this->base().'/'.implode('/', array_filter([$locale, $segment, $slug], fn ($p) => $p !== null && $p !== ''));
    }

    public function indexable(): bool
    {
        return (bool) $this->settings->get('seo', 'robots_index', true);
    }

    /** @return array<string, string> hreflang => url (includes x-default) */
    public function alternates(string $type, Model $m): array
    {
        $segment = self::TYPES[$type][1];
        $alt = [];
        foreach (self::LOCALES as $l) {
            $alt[$l] = $this->url($l, $segment, $m->{"slug_$l"});
        }
        $alt['x-default'] = $alt[$this->settings->get('site', 'default_locale', 'ar')] ?? $alt['ar'];

        return $alt;
    }

    /** @return array<string, mixed> */
    public function meta(string $type, Model $m, string $locale): array
    {
        $site = $this->settings->get('site', 'name', []);
        $siteName = $site[$locale] ?? 'PROMOFY';
        $name = $m->{"name_$locale"} ?? $m->{"title_$locale"} ?? null;
        $title = $m->{"seo_title_$locale"} ?: $name;
        $desc = $m->{"seo_description_$locale"} ?: strip_tags((string) ($m->{"short_description_$locale"} ?? $m->{"excerpt_$locale"} ?? $m->{"summary_$locale"} ?? ''));
        $desc = $desc !== '' ? mb_substr($desc, 0, 300) : (string) (($this->settings->get('seo', 'default_description', [])[$locale] ?? ''));
        $alternates = $this->alternates($type, $m);
        $canonical = $alternates[$locale];
        $image = null;
        if (method_exists($m, 'media') && ($first = $m->media()->first())) {
            $image = $first->url;
        }
        $image ??= $this->settings->get('seo', 'og_image_url');
        $noindex = ! $this->indexable() || (bool) ($m->seo_noindex ?? false);

        return [
            'title' => trim(($title ? "$title | " : '').$siteName, ' |'),
            'description' => $desc,
            'canonical' => $canonical,
            'robots' => $noindex ? 'noindex,nofollow' : 'index,follow',
            'alternates' => $alternates,
            'og' => ['type' => $type === 'article' ? 'article' : 'website', 'title' => $title ?: $siteName, 'description' => $desc, 'url' => $canonical, 'image' => $image,
                'locale' => $locale === 'ar' ? 'ar_AR' : 'en_US', 'site_name' => $siteName],
            'twitter' => ['card' => $image ? 'summary_large_image' : 'summary', 'title' => $title ?: $siteName, 'description' => $desc, 'image' => $image],
            'json_ld' => $this->jsonLd($type, $m, $locale, $name, $desc, $canonical, $image),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(string $type, Model $m, string $locale, ?string $name, string $desc, string $canonical, ?string $image): array
    {
        $org = ['@type' => 'Organization', 'name' => 'PROMOFY', 'url' => $this->base()];
        $logo = $this->settings->get('site', 'logo_url');
        if ($logo) {
            $org['logo'] = $logo;
        }
        $graph = [['@context' => 'https://schema.org'] + $org];
        $crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'PROMOFY', 'item' => $this->url($locale)]];
        if (self::TYPES[$type][1] !== '') {
            $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => self::TYPES[$type][1], 'item' => $this->url($locale, self::TYPES[$type][1])];
        }
        $crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $name, 'item' => $canonical];
        $graph[] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $crumbs];

        $main = match ($type) {
            'article' => ['@type' => 'Article', 'headline' => $name, 'datePublished' => optional($m->published_at)->toIso8601String(), 'dateModified' => optional($m->updated_at)->toIso8601String(), 'publisher' => $org],
            'product' => ['@type' => 'Product', 'name' => $name, 'sku' => $m->sku, 'offers' => ['@type' => 'Offer', 'price' => (string) $m->effectivePrice(), 'priceCurrency' => $m->currency_code,
                'availability' => (! $m->track_stock || $m->stock_quantity > 0 || $m->allow_backorder) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => $canonical]],
            'service' => ['@type' => 'Service', 'name' => $name, 'provider' => $org],
            default => ['@type' => 'WebPage', 'name' => $name],
        };
        $main += ['@context' => 'https://schema.org', 'description' => $desc, 'url' => $canonical, 'inLanguage' => $locale];
        if ($image) {
            $main['image'] = $image;
        }
        $graph[] = array_filter($main, fn ($v) => $v !== null && $v !== '');

        return $graph;
    }

    /** @return list<array{loc: string, alternates: array<string,string>, lastmod: ?string}> */
    public function sitemapEntries(): array
    {
        $entries = [];
        foreach (self::STATIC as $seg) {
            $alt = [];
            foreach (self::LOCALES as $l) {
                $alt[$l] = $this->url($l, $seg);
            }
            foreach (self::LOCALES as $l) {
                $entries[] = ['loc' => $alt[$l], 'alternates' => $alt + ['x-default' => $alt['ar']], 'lastmod' => null];
            }
        }
        foreach (self::TYPES as $type => [$model, $seg]) {
            $q = $model::query()->published();
            if (\Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), 'seo_noindex')) {
                $q->where('seo_noindex', false);
            }
            // system pages whose slug equals a static route (e.g. contact) would duplicate it
            foreach ($q->get() as $m) {
                $alt = $this->alternates($type, $m);
                foreach (self::LOCALES as $l) {
                    $entries[] = ['loc' => $alt[$l], 'alternates' => $alt, 'lastmod' => optional($m->updated_at)->toAtomString()];
                }
            }
        }
        $seen = [];

        return array_values(array_filter($entries, function ($e) use (&$seen) {
            return ! isset($seen[$e['loc']]) && ($seen[$e['loc']] = true);
        }));
    }

    public function sitemapXml(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";
        foreach ($this->sitemapEntries() as $e) {
            $x .= '<url><loc>'.e($e['loc']).'</loc>';
            if ($e['lastmod']) {
                $x .= '<lastmod>'.e($e['lastmod']).'</lastmod>';
            }
            foreach ($e['alternates'] as $hl => $href) {
                $x .= '<xhtml:link rel="alternate" hreflang="'.e($hl).'" href="'.e($href).'"/>';
            }
            $x .= "</url>\n";
        }

        return $x.'</urlset>';
    }

    public function robotsTxt(): string
    {
        if (! $this->indexable()) {
            return "User-agent: *\nDisallow: /\n";
        }
        $lines = ['User-agent: *', 'Allow: /'];
        foreach (['/admin', '/api', '/cart', '/checkout', '/account', '/order', '/orders', '/quotation', '/quotations', '/downloads'] as $p) {
            foreach (['', '/ar', '/en'] as $prefix) {
                $lines[] = "Disallow: {$prefix}{$p}";
            }
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.rtrim((string) config('app.url'), '/').'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }
}
