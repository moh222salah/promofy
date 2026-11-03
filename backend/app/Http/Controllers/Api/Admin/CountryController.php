<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Country;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CountryController extends CrudController
{
    protected string $model = Country::class;

    protected string $module = 'settings';

    protected array $searchable = ['code', 'name_ar', 'name_en'];

    protected array $filterable = ['is_active', 'is_market', 'currency_code'];

    protected array $sortable = ['id', 'code', 'name_en', 'sort_order'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected function rules(?Model $record): array
    {
        return [
            'code' => ['required', 'string', 'size:2', 'alpha', Rule::unique('countries', 'code')->ignore($record?->getKey())],
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'phone_code' => ['required', 'string', 'max:8', 'regex:/^\+?[0-9]{1,6}$/'],
            'currency_code' => ['nullable', 'string', 'size:3', 'exists:currencies,code'],
            'flag' => ['nullable', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'is_market' => ['sometimes', 'boolean'],
            'payment_methods' => ['nullable', 'array'],
            'contact' => ['nullable', 'array'],
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
        return ['value' => $record->code, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en, 'phone_code' => $record->phone_code];
    }
}
