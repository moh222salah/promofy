<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Article;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ArticleController extends ContentController
{
    protected string $model = Article::class;

    protected string $module = 'articles';

    protected array $searchable = ['title_ar', 'title_en', 'excerpt_en'];

    protected array $filterable = ['status', 'article_category_id', 'is_featured'];

    protected array $sortable = ['id', 'title_en', 'created_at', 'published_at', 'status'];

    protected string $defaultSort = '-id';

    protected array $with = ['media', 'category', 'author'];

    protected array $htmlFields = ['body_ar', 'body_en'];

    protected array $slugSources = ['slug_ar' => 'title_ar', 'slug_en' => 'title_en'];

    protected array $mediaCollections = ['cover'];

    protected function rules(?Model $record): array
    {
        return $this->slugRules('articles', $record) + $this->publishingRules() + [
            'article_category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'title_ar' => ['required', 'string', 'max:190'],
            'title_en' => ['required', 'string', 'max:190'],
            'excerpt_ar' => ['nullable', 'string', 'max:800'],
            'excerpt_en' => ['nullable', 'string', 'max:800'],
            'body_ar' => ['nullable', 'string', 'max:200000'],
            'body_en' => ['nullable', 'string', 'max:200000'],
            'reading_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'video_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $record = parent::persistNew($data, $request);
        $record->forceFill(['author_id' => $request->user()->id])->save();

        return $record;
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->title_ar, 'label_en' => $record->title_en];
    }
}
