<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\TeamMember;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;

class TeamMemberController extends ContentController
{
    protected string $model = TeamMember::class;

    protected string $module = 'team';

    protected ?string $statusColumn = null;

    protected array $searchable = ['name_ar', 'name_en', 'title_en'];

    protected array $filterable = ['is_active'];

    protected array $sortable = ['id', 'name_en', 'sort_order'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $with = ['media'];

    protected array $mediaCollections = ['photo'];

    protected function rules(?Model $record): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'title_ar' => ['nullable', 'string', 'max:190'],
            'title_en' => ['nullable', 'string', 'max:190'],
            'bio_ar' => ['nullable', 'string', 'max:2000'],
            'bio_en' => ['nullable', 'string', 'max:2000'],
            'social' => ['nullable', 'array', 'max:8'],
            'social.*' => ['nullable', 'url', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'media' => ['sometimes', 'array'],
            'media.*' => ['array', 'max:3'],
            'media.*.*' => ['integer', 'exists:media,id'],
        ];
    }

    protected function prepareDuplicate(Model $copy, Model $original): void
    {
        $copy->name_en = $original->name_en.' (copy)';
        $copy->name_ar = $original->name_ar.' (نسخة)';
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en];
    }
}
