<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Quotation;
use App\Services\Crm\QuotationService;
use App\Services\Settings\SettingsService;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuotationController extends CrudController
{
    protected string $model = Quotation::class;

    protected string $module = 'quotations';

    protected array $searchable = ['number', 'customer_name', 'customer_company', 'customer_email', 'title'];

    protected array $filterable = ['status', 'currency_code', 'assigned_to', 'customer_id'];

    protected array $sortable = ['id', 'number', 'total', 'status', 'valid_until', 'created_at'];

    protected array $with = ['items', 'assignee:id,name'];

    protected ?string $assignedColumn = 'assigned_to';

    public function __construct(private readonly QuotationService $quotes) {}

    protected function rules(?Model $record): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:190'],
            'customer_company' => ['nullable', 'string', 'max:190'],
            'customer_email' => ['nullable', 'email:rfc', 'max:190'],
            'title' => ['required', 'string', 'max:190'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'scope' => ['nullable', 'string', 'max:50000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'tax_rate' => ['sometimes', 'numeric', 'between:0,100'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('type', 'staff')],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.name_ar' => ['required', 'string', 'max:190'],
            'items.*.name_en' => ['required', 'string', 'max:190'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    protected function validated(Request $request, ?Model $record): array
    {
        if ($record && $record->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft quotations can be edited. Cancel it and create a new one.']]);
        }
        $data = parent::validated($request, $record);
        if (array_key_exists('assigned_to', $data) && ! Gate::allows('quotations.edit')
            && (int) $data['assigned_to'] !== (int) $request->user()->id) {
            abort(403, 'You can only assign quotations to yourself.');
        }

        return $data;
    }

    protected function persistNew(array $data, Request $request): Model
    {
        if (! empty($data['customer_id'])) {
            $c = Customer::find($data['customer_id']);
            $data['customer_name'] ??= $c->name;
            $data['customer_company'] ??= $c->company;
            $data['customer_email'] ??= $c->email;
        }
        if (! array_key_exists('tax_rate', $data)) {
            $data['tax_rate'] = (float) app(SettingsService::class)->get('checkout', 'tax_rate_percent', 0);
        }
        $data['assigned_to'] ??= $request->user()->id;

        $q = new Quotation;
        $q->fill(\Illuminate\Support\Arr::only($data, $q->getFillable()));
        $q->forceFill(['number' => 'TMP-'.$this->quotes->newToken(), 'public_token' => $this->quotes->newToken(), 'created_by' => $request->user()->id])->save();
        $q->forceFill(['number' => $this->quotes->newNumber($q)])->save();

        return $q;
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        if (array_key_exists('items', $data)) {
            $this->quotes->saveItems($record, $data['items']);
        } elseif (! $created && array_key_exists('tax_rate', $data)) {
            $this->quotes->saveItems($record, $record->items->map->only(['service_id', 'name_ar', 'name_en', 'description', 'quantity', 'unit_price', 'discount_percent'])->all());
        }
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if($record->status !== 'draft', 409, 'Only draft quotations can be deleted; cancel others instead.');
    }

    protected function exportColumns(): array
    {
        return ['Number' => 'number', 'Customer' => 'customer_name', 'Company' => 'customer_company', 'Title' => 'title', 'Currency' => 'currency_code',
            'Total' => 'total', 'Status' => 'status', 'Valid until' => 'valid_until', 'Created' => 'created_at'];
    }

    // ---------------------------------------------------------------- workflow
    public function send(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('send');
        $q = $this->findRecord($id, $this->scopeFor('edit'));

        return $this->respond($this->reload($this->quotes->transition($q, 'sent', 'staff')));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('approve');
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        return $this->respond($this->reload($this->quotes->transition($this->findRecord($id, 'all'), 'approved', 'staff', $data['note'] ?? null)));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('approve');
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);

        return $this->respond($this->reload($this->quotes->transition($this->findRecord($id, 'all'), 'rejected', 'staff', $data['note'])));
    }

    public function cancel(string $id): JsonResponse
    {
        $q = $this->findRecord($id, $this->scopeFor('edit'));

        return $this->respond($this->reload($this->quotes->transition($q, 'cancelled', 'staff')));
    }

    public function convertToOrder(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('approve');
        Gate::authorize('orders.edit');
        $order = $this->quotes->convertToOrder($this->findRecord($id, 'all'), $request->user()->id);

        return response()->json(['data' => ['order_id' => $order->id, 'number' => $order->number]], 201);
    }

    /** Printable document (use the browser's "Save as PDF"). */
    public function document(string $id)
    {
        $q = $this->findRecord($id, $this->scopeFor('view'), true);

        return response(view('quotation', ['q' => $q->load('items')])->render())->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Shareable link for the customer to review and decide. */
    public function link(string $id): JsonResponse
    {
        $this->authorizeAction('send');
        $q = $this->findRecord($id, $this->scopeFor('edit'));
        abort_if($q->status === 'draft', 422, 'Send the quotation first.');

        return response()->json(['data' => ['url' => rtrim((string) config('promofy.frontend_url'), '/').'/quotation/'.$q->number.'?token='.$q->public_token]]);
    }
}
