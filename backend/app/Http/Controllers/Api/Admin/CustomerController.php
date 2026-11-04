<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\CrmActivity;
use App\Models\Customer;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends CrudController
{
    protected string $model = Customer::class;

    protected string $module = 'customers';

    protected array $searchable = ['name', 'company', 'email', 'phone'];

    protected array $filterable = ['country_code', 'source', 'industry'];

    protected array $sortable = ['id', 'name', 'company', 'created_at'];

    protected array $withCount = ['leads'];

    protected function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190', Rule::unique('customers', 'email')->ignore($record?->getKey())->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{6,32}$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'industry' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', Rule::in(\App\Models\Lead::SOURCES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $customer = parent::persistNew($data, $request);
        $customer->forceFill(['created_by' => $request->user()->id])->save();

        return $customer;
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name' => 'name', 'Company' => 'company', 'Email' => 'email', 'Phone' => 'phone', 'Country' => 'country_code', 'Industry' => 'industry', 'Source' => 'source', 'Created' => 'created_at'];
    }

    /** Profile: customer, their leads, orders (by e-mail) and CRM timeline. */
    public function profile(string $id): JsonResponse
    {
        $this->authorizeAction('view');
        $customer = $this->findRecord($id, 'all', true);
        $orders = $customer->email && \Illuminate\Support\Facades\Gate::allows('orders.view')
            ? \App\Models\Order::where('customer_email', $customer->email)->latest('id')->limit(50)->get(['id', 'number', 'status', 'total', 'currency_code', 'created_at']) : [];

        return response()->json(['data' => [
            'customer' => $customer,
            'leads' => $customer->leads()->latest('id')->limit(50)->get(['id', 'name', 'status', 'source', 'created_at']),
            'orders' => $orders,
            'activities' => CrmActivity::with('user:id,name')->where('subject_type', 'customer')->where('subject_id', $customer->id)->latest('id')->limit(100)->get(),
        ]]);
    }
}
