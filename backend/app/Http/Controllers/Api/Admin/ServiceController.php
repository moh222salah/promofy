<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\CaseStudy;
use App\Models\PortfolioItem;
use App\Models\Service;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServiceController extends ContentController
{
    protected string $model = Service::class;

    protected string $module = 'services';

    protected array $searchable = ['name_ar', 'name_en', 'slug_en', 'short_description_en'];

    protected array $filterable = ['status', 'service_category_id', 'is_featured', 'pricing_type'];

    protected array $sortable = ['id', 'name_en', 'sort_order', 'created_at', 'published_at', 'status'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $with = ['media', 'category'];

    protected array $htmlFields = ['description_ar', 'description_en'];

    protected array $slugSources = ['slug_ar' => 'name_ar', 'slug_en' => 'name_en'];

    protected array $mediaCollections = ['main_image', 'gallery'];

    protected function rules(?Model $record): array
    {
        $pair = fn (array $extra = []) => ['nullable', 'array', 'max:40'];

        return $this->slugRules('services', $record) + $this->publishingRules() + [
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'short_description_ar' => ['nullable', 'string', 'max:600'],
            'short_description_en' => ['nullable', 'string', 'max:600'],
            'description_ar' => ['nullable', 'string', 'max:100000'],
            'description_en' => ['nullable', 'string', 'max:100000'],
            'features' => $pair(), 'features.*.ar' => ['nullable', 'string', 'max:300'], 'features.*.en' => ['nullable', 'string', 'max:300'],
            'deliverables' => $pair(), 'deliverables.*.ar' => ['nullable', 'string', 'max:300'], 'deliverables.*.en' => ['nullable', 'string', 'max:300'],
            'process_steps' => $pair(),
            'process_steps.*.title_ar' => ['nullable', 'string', 'max:190'], 'process_steps.*.title_en' => ['nullable', 'string', 'max:190'],
            'process_steps.*.text_ar' => ['nullable', 'string', 'max:1000'], 'process_steps.*.text_en' => ['nullable', 'string', 'max:1000'],
            'faqs' => $pair(),
            'faqs.*.q_ar' => ['nullable', 'string', 'max:300'], 'faqs.*.q_en' => ['nullable', 'string', 'max:300'],
            'faqs.*.a_ar' => ['nullable', 'string', 'max:2000'], 'faqs.*.a_en' => ['nullable', 'string', 'max:2000'],
            'duration_ar' => ['nullable', 'string', 'max:190'],
            'duration_en' => ['nullable', 'string', 'max:190'],
            'pricing_type' => ['sometimes', Rule::in(['quote', 'range', 'fixed'])],
            'price_from' => ['nullable', 'numeric', 'min:0', 'required_if:pricing_type,range,fixed'],
            'price_to' => ['nullable', 'numeric', 'gte:price_from', 'required_if:pricing_type,range'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code', 'required_if:pricing_type,range,fixed'],
            'cta_type' => ['sometimes', Rule::in(['quote', 'whatsapp', 'contact', 'consultation'])],
            'cta_label_ar' => ['nullable', 'string', 'max:190'],
            'cta_label_en' => ['nullable', 'string', 'max:190'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'related_portfolio_ids' => ['sometimes', 'array', 'max:50'],
            'related_portfolio_ids.*' => ['integer', 'exists:portfolio_items,id'],
            'related_case_study_ids' => ['sometimes', 'array', 'max:50'],
            'related_case_study_ids.*' => ['integer', 'exists:case_studies,id'],
        ];
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        parent::afterSave($record, $data, $request, $created);

        foreach (['related_portfolio_ids' => 'portfolio_item', 'related_case_study_ids' => 'case_study'] as $key => $type) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            DB::table('service_links')->where('service_id', $record->id)->where('linkable_type', $type)->delete();
            foreach (array_unique($data[$key]) as $id) {
                DB::table('service_links')->insert(['service_id' => $record->id, 'linkable_type' => $type, 'linkable_id' => $id]);
            }
        }
    }

    public function show(string $id)
    {
        $response = parent::show($id);
        $payload = $response->toResponse(request())->getData(true);
        $payload['data']['related_portfolio_ids'] = DB::table('service_links')->where('service_id', $id)->where('linkable_type', 'portfolio_item')->pluck('linkable_id');
        $payload['data']['related_case_study_ids'] = DB::table('service_links')->where('service_id', $id)->where('linkable_type', 'case_study')->pluck('linkable_id');

        return response()->json($payload);
    }

    protected function afterDuplicate(Model $copy, Model $original): void
    {
        parent::afterDuplicate($copy, $original);
        foreach (DB::table('service_links')->where('service_id', $original->id)->get() as $link) {
            DB::table('service_links')->insert(['service_id' => $copy->id, 'linkable_type' => $link->linkable_type, 'linkable_id' => $link->linkable_id]);
        }
    }

    protected function bulkRules(): array
    {
        return ['service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'], 'is_featured' => ['boolean']];
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name (EN)' => 'name_en', 'Name (AR)' => 'name_ar', 'Category' => 'category.name_en', 'Pricing' => 'pricing_type', 'Status' => 'status', 'Featured' => 'is_featured'];
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en];
    }
}
