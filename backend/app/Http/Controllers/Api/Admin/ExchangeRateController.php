<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ExchangeRate;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Rates are entered by finance/administrators; the platform never assumes a fixed rate. */
class ExchangeRateController extends CrudController
{
    protected string $model = ExchangeRate::class;

    protected string $module = 'pricing';

    protected array $filterable = ['base_currency', 'quote_currency'];

    protected array $sortable = ['id', 'effective_at', 'base_currency', 'quote_currency'];

    protected string $defaultSort = '-effective_at';

    protected string $dateColumn = 'effective_at';

    protected function rules(?Model $record): array
    {
        return [
            'base_currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'quote_currency' => ['required', 'string', 'size:3', 'exists:currencies,code', 'different:base_currency'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'source' => ['sometimes', 'string', 'max:80'],
            'effective_at' => ['sometimes', 'date'],
        ];
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $rate = new ExchangeRate;
        $rate->fill($data + ['effective_at' => now(), 'source' => 'manual']);
        $rate->forceFill(['created_by' => $request->user()->id])->save();

        return $rate;
    }
}
