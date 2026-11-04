<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Admin\NavigationController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\CaseStudy;
use App\Models\ContactMessage;
use App\Models\Page;
use App\Models\PortfolioItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TeamMember;
use App\Models\Testimonial;
use App\Services\Content\HomepageService;
use App\Support\Content\PublicPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only public content endpoints plus the contact form. Only live (published, not scheduled) records are exposed. */
class PublicContentController extends Controller
{
    private function cached(JsonResponse $r): JsonResponse
    {
        return $r->header('Cache-Control', 'public, max-age=60');
    }

    private function paginate(Request $request, Builder $query, string $kind): JsonResponse
    {
        $page = $query->paginate(min((int) $request->query('per_page', 12), 48))->withQueryString();

        return $this->cached(response()->json([
            'data' => $page->getCollection()->map(fn ($m) => PublicPresenter::present($m, $kind))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]));
    }

    private function search(Builder $q, Request $request, array $columns): Builder
    {
        $term = trim((string) $request->query('search', ''));
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $q->where(function (Builder $w) use ($columns, $like) {
                foreach ($columns as $c) {
                    $w->orWhereRaw("lower({$c}) like ? escape '\\'", [$like]);
                }
            });
        }

        return $q;
    }

    private function bySlug(string $model, string $slug, array $with)
    {
        return $model::query()->published()->with($with)
            ->where(fn ($q) => $q->where('slug_en', $slug)->orWhere('slug_ar', $slug))->firstOrFail();
    }

    // ------------------------------------------------------------------ homepage / navigation
    public function homepage(HomepageService $homepage): JsonResponse
    {
        $published = $homepage->published();

        return $this->cached(response()->json(['data' => [
            'sections' => $homepage->resolve($published?->sections ?? \App\Support\Content\HomepageSections::defaults()),
            'published_at' => $published?->published_at,
        ]]));
    }

    public function navigation(): JsonResponse
    {
        return $this->cached(response()->json(['data' => [
            'header' => NavigationController::tree('header', true),
            'footer' => NavigationController::tree('footer', true),
        ]]));
    }

    // ------------------------------------------------------------------ services
    public function serviceCategories(): JsonResponse
    {
        $cats = ServiceCategory::where('is_active', true)->orderBy('sort_order')->get();

        return $this->cached(response()->json(['data' => $cats->map(fn ($c) => $c->only([
            'id', 'slug_ar', 'slug_en', 'name_ar', 'name_en', 'description_ar', 'description_en', 'icon', 'sort_order',
        ]))]));
    }

    public function services(Request $request): JsonResponse
    {
        $q = Service::query()->published()->with(['media', 'category'])->orderBy('sort_order')->orderBy('id');
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug_en', $cat)->orWhere('slug_ar', $cat)->orWhere('id', $cat));
        }
        if ($request->boolean('featured')) {
            $q->where('is_featured', true);
        }
        $this->search($q, $request, ['name_ar', 'name_en', 'short_description_ar', 'short_description_en']);

        return $this->paginate($request, $q, 'service');
    }

    public function service(string $slug): JsonResponse
    {
        $service = $this->bySlug(Service::class, $slug, ['media', 'category']);
        $data = PublicPresenter::present($service, 'service');
        $links = DB::table('service_links')->where('service_id', $service->id)->get()->groupBy('linkable_type');
        $data['related_projects'] = PortfolioItem::published()->with('media')->whereKey($links->get('portfolio_item', collect())->pluck('linkable_id'))->get()
            ->map(fn ($m) => PublicPresenter::present($m, 'portfolio'))->values();
        $data['related_case_studies'] = CaseStudy::published()->with('media')->whereKey($links->get('case_study', collect())->pluck('linkable_id'))->get()
            ->map(fn ($m) => PublicPresenter::present($m, 'case_study'))->values();

        return $this->cached(response()->json(['data' => $data]));
    }

    // ------------------------------------------------------------------ portfolio / case studies
    public function portfolio(Request $request): JsonResponse
    {
        $q = PortfolioItem::query()->published()->with(['media', 'category'])->orderBy('sort_order')->orderByDesc('id');
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug_en', $cat)->orWhere('slug_ar', $cat)->orWhere('id', $cat));
        }
        $this->search($q, $request, ['title_ar', 'title_en', 'summary_ar', 'summary_en']);

        return $this->paginate($request, $q, 'portfolio');
    }

    public function portfolioItem(string $slug): JsonResponse
    {
        $item = $this->bySlug(PortfolioItem::class, $slug, ['media', 'category']);
        $data = PublicPresenter::present($item, 'portfolio');
        $data['related'] = PortfolioItem::published()->with('media')->whereKeyNot($item->id)
            ->when($item->service_category_id, fn ($q) => $q->where('service_category_id', $item->service_category_id))
            ->orderBy('sort_order')->limit(3)->get()->map(fn ($m) => PublicPresenter::present($m, 'portfolio'))->values();
        $data['related_services'] = $this->servicesLinkedTo('portfolio_item', $item->id);

        return $this->cached(response()->json(['data' => $data]));
    }

    public function caseStudies(Request $request): JsonResponse
    {
        $q = CaseStudy::query()->published()->with('media')->orderBy('sort_order')->orderByDesc('id');
        $this->search($q, $request, ['title_ar', 'title_en', 'industry_en', 'industry_ar']);

        return $this->paginate($request, $q, 'case_study');
    }

    public function caseStudy(string $slug): JsonResponse
    {
        $item = $this->bySlug(CaseStudy::class, $slug, ['media']);
        $data = PublicPresenter::present($item, 'case_study');
        $data['related_services'] = $this->servicesLinkedTo('case_study', $item->id);

        return $this->cached(response()->json(['data' => $data]));
    }

    private function servicesLinkedTo(string $type, int $id)
    {
        $ids = DB::table('service_links')->where('linkable_type', $type)->where('linkable_id', $id)->pluck('service_id');

        return Service::published()->with('media')->whereKey($ids)->get()->map(fn ($s) => PublicPresenter::present($s, 'service'))->values();
    }

    // ------------------------------------------------------------------ insights
    public function articleCategories(): JsonResponse
    {
        return $this->cached(response()->json(['data' => ArticleCategory::where('is_active', true)->orderBy('sort_order')->get(['id', 'slug_ar', 'slug_en', 'name_ar', 'name_en'])]));
    }

    public function articles(Request $request): JsonResponse
    {
        $q = Article::query()->published()->with(['media', 'category'])->orderByDesc('published_at')->orderByDesc('id');
        if ($cat = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug_en', $cat)->orWhere('slug_ar', $cat)->orWhere('id', $cat));
        }
        $this->search($q, $request, ['title_ar', 'title_en', 'excerpt_ar', 'excerpt_en']);

        return $this->paginate($request, $q, 'article');
    }

    public function article(string $slug): JsonResponse
    {
        $article = $this->bySlug(Article::class, $slug, ['media', 'category']);
        $data = PublicPresenter::present($article, 'article');
        $data['related'] = Article::published()->with('media')->whereKeyNot($article->id)
            ->when($article->article_category_id, fn ($q) => $q->where('article_category_id', $article->article_category_id))
            ->orderByDesc('published_at')->limit(3)->get()->map(fn ($m) => PublicPresenter::present($m, 'article'))->values();

        return $this->cached(response()->json(['data' => $data]));
    }

    // ------------------------------------------------------------------ pages / testimonials / team
    public function page(string $slug): JsonResponse
    {
        return $this->cached(response()->json(['data' => PublicPresenter::present($this->bySlug(Page::class, $slug, ['media']), 'page')]));
    }

    public function testimonials(): JsonResponse
    {
        $items = Testimonial::published()->where('consent_given', true)->with('media')->orderBy('sort_order')->get();

        return $this->cached(response()->json(['data' => $items->map(fn ($m) => PublicPresenter::present($m, 'testimonial'))->values()]));
    }

    public function team(): JsonResponse
    {
        $items = TeamMember::where('is_active', true)->with('media')->orderBy('sort_order')->get();

        return $this->cached(response()->json(['data' => $items->map(fn ($m) => PublicPresenter::present($m, 'team'))->values()]));
    }

    // ------------------------------------------------------------------ contact form
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{6,32}$/'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'min:5', 'max:5000'],
            'consent' => ['accepted'],
            'attribution' => ['nullable', 'array'],
            'attribution.*' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string'], // honeypot
        ]);

        // Bots fill the hidden "website" field; answer as if it worked but store nothing.
        if (! empty($data['website'])) {
            return response()->json(['data' => ['received' => true]], 201);
        }

        $message = new ContactMessage(\Illuminate\Support\Arr::only($data, ['name', 'email', 'phone', 'subject', 'message']));
        $message->forceFill([
            'attribution' => array_slice($data['attribution'] ?? [], 0, 12),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ])->save();

        return response()->json(['data' => ['received' => true, 'reference' => 'MSG-'.str_pad((string) $message->id, 6, '0', STR_PAD_LEFT)]], 201);
    }
}
