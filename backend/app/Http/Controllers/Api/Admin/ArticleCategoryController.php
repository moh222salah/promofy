<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ArticleCategory;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ArticleCategoryController extends ContentController
{
    protected string $model = ArticleCategory::class;

    protected string $module = 'articles';

    protected ?string $statusColumn = null;

    protected array $with = [];

    protected array $searchable = ['name_ar', 'name_en'];

    protected array $filterable = ['is_active'];

    protected array $sortable = ['id', 'name_en', 'sort_order'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $slugSources = ['slug_ar' => 'name_ar', 'slug_en' => 'name_en'];

    protected function rules(?Model $record): array
    {
        return $this->slugRules('article_categories', $record) + [
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if(\App\Models\Article::where('article_category_id', $record->id)->exists(), 409, 'This category still contains articles.');
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en];
    }
}
