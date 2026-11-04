<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Payment;
use App\Support\Crud\CrudController;

/** Read-only ledger of payments; money movements happen via order endpoints and verified webhooks. */
class PaymentController extends CrudController
{
    protected string $model = Payment::class;

    protected string $module = 'payments';

    protected array $searchable = ['provider_reference', 'provider', 'note'];

    protected array $filterable = ['status', 'provider', 'currency_code'];

    protected array $sortable = ['id', 'amount', 'status', 'created_at'];

    protected array $with = ['order:id,number,customer_name', 'refunds'];

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Order' => 'order.number', 'Provider' => 'provider', 'Reference' => 'provider_reference', 'Amount' => 'amount',
            'Currency' => 'currency_code', 'Status' => 'status', 'Refunded' => 'refunded_total', 'Verified at' => 'verified_at', 'Created' => 'created_at'];
    }
}
