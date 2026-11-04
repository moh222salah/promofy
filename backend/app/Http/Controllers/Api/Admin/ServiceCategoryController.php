<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ServiceCategory;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ServiceCategoryController extends ContentController
{
    protected string $model = ServiceCategory::class;

    protected string $module = 'services';

    protected ?string $statusColumn = null;

    protected array $searchable = ['name_ar', 'name_en', 'slug_en'];

    protected array $filterable = ['is_active'];

    protected array $sortable = ['id', 'name_en', 'sort_order'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $withCount = ['services'];

    protected array $with = [];

    protected array $slugSources = ['slug_ar' => 'name_ar', 'slug_en' => 'name_en'];

    protected function rules(?Model $record): array
    {
        return $this->slugRules('service_categories', $record) + [
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:48'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if($record->services()->exists(), 409, 'This category still contains services. Move or delete them first.');
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en];
    }

    protected function onStatusChange(Model $record, string $status): void {}
}
