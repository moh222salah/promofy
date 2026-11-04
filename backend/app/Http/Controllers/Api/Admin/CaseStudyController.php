<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\CaseStudy;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CaseStudyController extends ContentController
{
    protected string $model = CaseStudy::class;

    protected string $module = 'case_studies';

    protected array $searchable = ['title_ar', 'title_en', 'client_name', 'industry_en'];

    protected array $filterable = ['status', 'is_featured', 'results_verified'];

    protected array $sortable = ['id', 'title_en', 'sort_order', 'created_at', 'status'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $with = ['media'];

    protected array $htmlFields = [
        'overview_ar', 'overview_en', 'challenge_ar', 'challenge_en', 'approach_ar', 'approach_en',
        'execution_ar', 'execution_en', 'campaign_ar', 'campaign_en',
    ];

    protected array $slugSources = ['slug_ar' => 'title_ar', 'slug_en' => 'title_en'];

    protected array $mediaCollections = ['cover', 'gallery'];

    protected function rules(?Model $record): array
    {
        $html = ['nullable', 'string', 'max:100000'];

        return $this->slugRules('case_studies', $record) + $this->publishingRules() + [
            'title_ar' => ['required', 'string', 'max:190'],
            'title_en' => ['required', 'string', 'max:190'],
            'client_name' => ['nullable', 'string', 'max:190'],
            'client_approved' => ['sometimes', 'boolean'],
            'industry_ar' => ['nullable', 'string', 'max:190'],
            'industry_en' => ['nullable', 'string', 'max:190'],
            'overview_ar' => $html, 'overview_en' => $html, 'challenge_ar' => $html, 'challenge_en' => $html,
            'approach_ar' => $html, 'approach_en' => $html, 'execution_ar' => $html, 'execution_en' => $html,
            'campaign_ar' => $html, 'campaign_en' => $html,
            'results' => ['nullable', 'array', 'max:20'],
            'results.*.label_ar' => ['required', 'string', 'max:190'],
            'results.*.label_en' => ['required', 'string', 'max:190'],
            'results.*.value' => ['required', 'string', 'max:64'],
            'results.*.source_note' => ['nullable', 'string', 'max:300'],
            'results_verified' => ['sometimes', 'boolean'],
        ];
    }

    /** Results may only be published once an editor has confirmed they come from verified client data. */
    protected function validated(\Illuminate\Http\Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);

        $status = $data['status'] ?? $record?->status;
        $results = array_key_exists('results', $data) ? $data['results'] : $record?->results;
        $verified = $data['results_verified'] ?? $record?->results_verified ?? false;
        if ($status === 'published' && ! empty($results) && ! $verified) {
            throw ValidationException::withMessages(['results_verified' => ['Results must be marked as verified before a case study with results can be published.']]);
        }

        return $data;
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->title_ar, 'label_en' => $record->title_en];
    }
}
