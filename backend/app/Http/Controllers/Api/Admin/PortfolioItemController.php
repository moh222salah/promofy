<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\PortfolioItem;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;

class PortfolioItemController extends ContentController
{
    protected string $model = PortfolioItem::class;

    protected string $module = 'portfolio';

    protected array $searchable = ['title_ar', 'title_en', 'client_name'];

    protected array $filterable = ['status', 'service_category_id', 'is_featured'];

    protected array $sortable = ['id', 'title_en', 'sort_order', 'created_at', 'completed_at', 'status'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $with = ['media', 'category'];

    protected array $htmlFields = ['brief_ar', 'brief_en'];

    protected array $slugSources = ['slug_ar' => 'title_ar', 'slug_en' => 'title_en'];

    protected array $mediaCollections = ['cover', 'gallery'];

    protected function rules(?Model $record): array
    {
        return $this->slugRules('portfolio_items', $record) + $this->publishingRules() + [
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'title_ar' => ['required', 'string', 'max:190'],
            'title_en' => ['required', 'string', 'max:190'],
            'client_name' => ['nullable', 'string', 'max:190'],
            'client_approved' => ['sometimes', 'boolean'],
            'summary_ar' => ['nullable', 'string', 'max:800'],
            'summary_en' => ['nullable', 'string', 'max:800'],
            'brief_ar' => ['nullable', 'string', 'max:100000'],
            'brief_en' => ['nullable', 'string', 'max:100000'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'completed_at' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:48'],
        ];
    }

    protected function bulkRules(): array
    {
        return ['service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'], 'is_featured' => ['boolean']];
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->title_ar, 'label_en' => $record->title_en];
    }
}
