<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Currency;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CurrencyController extends CrudController
{
    protected string $model = Currency::class;

    protected string $module = 'pricing';

    protected array $searchable = ['code', 'name_ar', 'name_en'];

    protected array $filterable = ['is_active'];

    protected array $sortable = ['id', 'code', 'sort_order'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique('currencies', 'code')->ignore($record?->getKey())],
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'symbol_ar' => ['required', 'string', 'max:12'],
            'symbol_en' => ['required', 'string', 'max:12'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function persistNew(array $data, \Illuminate\Http\Request $request): Model
    {
        $data['code'] = strtoupper($data['code']);

        return parent::persistNew($data, $request);
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->code, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en];
    }
}
