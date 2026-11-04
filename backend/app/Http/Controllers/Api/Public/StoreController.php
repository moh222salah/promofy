<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\Content\PublicPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public static function present(Product $p): array
    {
        $data = PublicPresenter::present($p, 'product');
        unset($data['stock_quantity'], $data['low_stock_threshold'], $data['allow_backorder']);
        $data['effective_price'] = $p->effectivePrice();
        $data['in_stock'] = ! $p->track_stock || $p->has_variants || $p->stock_quantity > 0 || $p->allow_backorder;
        $data['is_digital'] = in_array($p->type, Product::DIGITAL_TYPES, true);
        if ($p->relationLoaded('variants')) {
            $data['variants'] = $p->variants->where('is_active', true)->values()->map(fn ($v) => [
                'id' => $v->id, 'sku' => $v->sku, 'name_ar' => $v->name_ar, 'name_en' => $v->name_en, 'option_values' => $v->option_values,
                'price' => $v->price ?? $p->price, 'effective_price' => $p->effectivePrice($v),
                'in_stock' => ! $p->track_stock || $v->stock_quantity > 0 || $p->allow_backorder,
            ]);
        }
        unset($data['files']);

        return $data;
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => ProductCategory::where('is_active', true)->orderBy('sort_order')->get(['id', 'slug_ar', 'slug_en', 'name_ar', 'name_en', 'description_ar', 'description_en'])])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function index(Request $request): JsonResponse
    {
        $q = Product::query()->published()->with(['media', 'category', 'variants']);
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug_en', $cat)->orWhere('slug_ar', $cat)->orWhere('id', $cat));
        }
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($request->boolean('featured')) {
            $q->where('is_featured', true);
        }
        if (is_numeric($min = $request->query('min_price'))) {
            $q->where('price', '>=', $min);
        }
        if (is_numeric($max = $request->query('max_price'))) {
            $q->where('price', '<=', $max);
        }
        if (($term = trim((string) $request->query('search', ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $q->where(fn ($w) => $w->whereRaw("lower(name_ar) like ? escape '\\'", [$like])->orWhereRaw("lower(name_en) like ? escape '\\'", [$like])
                ->orWhereRaw("lower(short_description_en) like ? escape '\\'", [$like])->orWhereRaw("lower(short_description_ar) like ? escape '\\'", [$like]));
        }
        match ($request->query('sort')) {
            'price_asc' => $q->orderBy('price'),
            'price_desc' => $q->orderByDesc('price'),
            'newest' => $q->orderByDesc('published_at'),
            default => $q->orderBy('sort_order')->orderByDesc('id'),
        };

        $page = $q->paginate(min((int) $request->query('per_page', 12), 48))->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($p) => self::present($p))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $p = Product::query()->published()->with(['media', 'category', 'variants'])->where(fn ($q) => $q->where('slug_en', $slug)->orWhere('slug_ar', $slug))->firstOrFail();
        $data = self::present($p);
        $data['related'] = Product::published()->with('media')->whereKeyNot($p->id)
            ->when($p->product_category_id, fn ($q) => $q->where('product_category_id', $p->product_category_id))
            ->limit(4)->get()->map(fn ($r) => self::present($r))->values();

        return response()->json(['data' => $data]);
    }
}
