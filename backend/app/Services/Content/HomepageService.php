<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\CaseStudy;
use App\Models\HomepageVersion;
use App\Models\PortfolioItem;
use App\Models\Service;
use App\Models\Testimonial;
use App\Support\Content\HomepageSections;
use App\Support\Content\PublicPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Draft / publish workflow of the homepage plus resolution of picked content for rendering. */
class HomepageService
{
    public function draft(): HomepageVersion
    {
        return HomepageVersion::where('status', 'draft')->first()
            ?? HomepageVersion::create(['status' => 'draft', 'sections' => $this->published()?->sections ?? HomepageSections::defaults()]);
    }

    public function published(): ?HomepageVersion
    {
        return HomepageVersion::where('status', 'published')->latest('id')->first();
    }

    /** @param  list<array<string, mixed>>  $sections */
    public function saveDraft(array $sections, int $userId): HomepageVersion
    {
        $draft = $this->draft();
        $draft->forceFill(['sections' => array_values($sections), 'created_by' => $userId])->save();

        return $draft;
    }

    public function publishDraft(int $userId): HomepageVersion
    {
        return DB::transaction(function () use ($userId) {
            $draft = $this->draft();

            return HomepageVersion::create(['status' => 'published', 'sections' => $draft->sections, 'created_by' => $userId, 'published_at' => now()]);
        });
    }

    /**
     * Turn stored sections into render-ready data: enabled sections only, picked ids resolved to
     * published content (anything unpublished since is silently skipped).
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    public function resolve(array $sections, bool $preview = false): array
    {
        $out = [];
        foreach ($sections as $section) {
            if (! ($section['enabled'] ?? false)) {
                continue;
            }
            $settings = (array) ($section['settings'] ?? []);
            $ids = array_map('intval', $settings['ids'] ?? []);
            $section['items'] = match ($section['type']) {
                'featured_services' => $this->pick(Service::class, $ids, ['media', 'category'], 'service'),
                'selected_projects' => $this->pick(PortfolioItem::class, $ids, ['media'], 'portfolio'),
                'featured_case_studies' => $this->pick(CaseStudy::class, $ids, ['media'], 'case_study'),
                'insights' => $this->pick(Article::class, $ids, ['media', 'category'], 'article'),
                'testimonials' => $this->pick(Testimonial::class, $ids, ['media'], 'testimonial'),
                'featured_products' => Schema::hasTable('products') ? $this->pickProducts($ids) : [],
                default => null,
            };
            if (! empty($settings['media_id'])) {
                $section['media'] = ($m = \App\Models\Media::find($settings['media_id'])) ? PublicPresenter::media($m) : null;
            }
            if (! empty($settings['video_media_id'])) {
                $section['video'] = ($m = \App\Models\Media::find($settings['video_media_id'])) ? PublicPresenter::media($m) : null;
            }
            $out[] = $section;
        }

        return $out;
    }

    private function pick(string $model, array $ids, array $with, string $kind): array
    {
        $query = $model::query()->published()->with($with);
        if ($ids !== []) {
            $query->whereKey($ids);
        } else {
            $query->where('is_featured', true)->orderBy('sort_order')->limit(8);
        }
        $items = $query->get();
        if ($ids !== []) {
            $items = $items->sortBy(fn ($i) => array_search($i->id, $ids, true))->values();
        }

        return $items->map(fn ($i) => PublicPresenter::present($i, $kind))->all();
    }

    private function pickProducts(array $ids): array
    {
        return []; // wired up when the store domain lands
    }
}
