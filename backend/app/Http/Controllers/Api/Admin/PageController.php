<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Page;
use App\Models\PageRevision;
use App\Support\Audit\AuditLogger;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PageController extends ContentController
{
    protected string $model = Page::class;

    protected string $module = 'pages';

    protected array $searchable = ['title_ar', 'title_en', 'slug_en', 'slug_ar'];

    protected array $filterable = ['status', 'template', 'is_system'];

    protected array $sortable = ['id', 'title_en', 'sort_order', 'created_at', 'updated_at', 'status'];

    protected string $defaultSort = 'sort_order';

    protected array $with = ['media'];

    protected array $htmlFields = ['content_ar', 'content_en'];

    protected array $slugSources = ['slug_ar' => 'title_ar', 'slug_en' => 'title_en'];

    protected array $mediaCollections = ['cover'];

    private const SNAPSHOT = ['title_ar', 'title_en', 'content_ar', 'content_en', 'slug_ar', 'slug_en', 'template',
        'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'status', 'published_at'];

    protected function rules(?Model $record): array
    {
        return $this->slugRules('pages', $record) + $this->publishingRules() + [
            'parent_id' => ['nullable', 'integer', 'exists:pages,id', Rule::notIn([$record?->getKey()])],
            'title_ar' => ['required', 'string', 'max:190'],
            'title_en' => ['required', 'string', 'max:190'],
            'content_ar' => ['nullable', 'string', 'max:300000'],
            'content_en' => ['nullable', 'string', 'max:300000'],
            'template' => ['sometimes', Rule::in(['default', 'wide', 'legal', 'landing'])],
        ];
    }

    protected function beforeUpdate(Model $record, array $data, Request $request): void
    {
        $this->snapshot($record, $request->user()->id);
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if($record->is_system, 409, 'System pages cannot be deleted; unpublish them instead.');
        abort_if(Page::where('parent_id', $record->id)->exists(), 409, 'This page has child pages.');
    }

    public function revisions(string $id): JsonResponse
    {
        $this->authorizeAction('view');
        $page = $this->findRecord($id, 'all', true);

        return response()->json(['data' => $page->revisions()->with('author:id,name')->limit(100)->get()]);
    }

    public function restore(Request $request, string $id, string $revision): JsonResponse
    {
        $this->authorizeAction('edit');
        $page = $this->findRecord($id, 'all');
        $rev = PageRevision::where('page_id', $page->id)->findOrFail($revision);

        DB::transaction(function () use ($page, $rev, $request) {
            $this->snapshot($page, $request->user()->id);
            $snap = Arr::only($rev->snapshot, self::SNAPSHOT);
            // restoring never changes the publication state or URL: only the content
            unset($snap['status'], $snap['published_at'], $snap['slug_ar'], $snap['slug_en']);
            $page->fill($snap)->save();
            AuditLogger::record('page.revision_restored', $page, [], [], ['revision_id' => $rev->id]);
        });

        return $this->respond($this->reload($page));
    }

    protected function snapshot(Model $page, int $userId): void
    {
        PageRevision::create(['page_id' => $page->id, 'snapshot' => Arr::only($page->getAttributes(), self::SNAPSHOT), 'created_by' => $userId]);
        // keep history bounded
        $keep = PageRevision::where('page_id', $page->id)->orderByDesc('id')->limit(50)->pluck('id');
        PageRevision::where('page_id', $page->id)->whereNotIn('id', $keep)->delete();
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->title_ar, 'label_en' => $record->title_en];
    }
}
