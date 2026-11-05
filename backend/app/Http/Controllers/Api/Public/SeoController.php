<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function __construct(private SeoService $seo) {}

    /** Meta tags, hreflang alternates and JSON-LD for one live record — used by the SPA head and by prerendering. */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:'.implode(',', array_keys(SeoService::TYPES))], 'slug' => ['required', 'string', 'max:255'], 'locale' => ['nullable', 'in:ar,en']]);
        $locale = $data['locale'] ?? 'ar';
        [$model] = SeoService::TYPES[$data['type']];
        $record = $model::query()->published()->where(fn ($q) => $q->where('slug_ar', $data['slug'])->orWhere('slug_en', $data['slug']))->firstOrFail();

        return response()->json(['data' => $this->seo->meta($data['type'], $record, $locale)]);
    }

    public function sitemap(): Response
    {
        return response($this->seo->sitemapXml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }

    public function robots(): Response
    {
        return response($this->seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=900']);
    }
}
