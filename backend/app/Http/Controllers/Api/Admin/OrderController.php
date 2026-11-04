<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Services\Store\OrderService;
use App\Support\Audit\AuditLogger;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Orders are read through the generic kit; every state change goes through OrderService / PaymentService. */
class OrderController extends CrudController
{
    protected string $model = Order::class;

    protected string $module = 'orders';

    protected array $searchable = ['number', 'customer_name', 'customer_email', 'customer_phone'];

    protected array $filterable = ['status', 'method', 'currency_code', 'country_code', 'assigned_to'];

    protected array $sortable = ['id', 'number', 'total', 'status', 'created_at', 'paid_at'];

    protected array $with = ['items', 'assignee:id,name'];

    protected function exportColumns(): array
    {
        return ['Number' => 'number', 'Date' => 'created_at', 'Customer' => 'customer_name', 'Email' => 'customer_email', 'Phone' => 'customer_phone',
            'Country' => 'country_code', 'Method' => 'method', 'Status' => 'status', 'Currency' => 'currency_code', 'Subtotal' => 'subtotal',
            'Discount' => 'discount_total', 'Tax' => 'tax_total', 'Total' => 'total', 'Paid at' => 'paid_at'];
    }

    public function show(string $id)
    {
        $this->authorizeAction('view');
        $order = Order::with(['items', 'payments.refunds', 'notes.author:id,name', 'history.user:id,name', 'assignee:id,name'])->findOrFail($id);
        if (! \Illuminate\Support\Facades\Gate::allows('payments.view')) {
            $order->unsetRelation('payments');
        }

        return response()->json(['data' => $order]);
    }

    public function transition(Request $request, OrderService $orders, string $id): JsonResponse
    {
        $this->authorizeAction(in_array($request->input('status'), ['cancelled'], true) ? 'cancel' : 'edit');
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(OrderService::WORKFLOW))], 'note' => ['nullable', 'string', 'max:255']]);
        abort_if(in_array($data['status'], ['paid', 'refunded'], true), 422, 'Paid and refunded are set only by recorded payments and refunds.');
        $order = $orders->transition(Order::findOrFail($id), $data['status'], $request->user()->id, $data['note'] ?? null);
        AuditLogger::record('order.status_changed', $order, [], ['status' => $data['status']], ['note' => $data['note'] ?? null]);

        return $this->respond($order->load('items'));
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('assign');
        $data = $request->validate(['user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('type', 'staff')]]);
        $order = Order::findOrFail($id);
        $order->forceFill(['assigned_to' => $data['user_id']])->save();

        return $this->respond($order->load('assignee:id,name'));
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('edit');
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $note = OrderNote::create(['order_id' => Order::findOrFail($id)->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }

    public function recordPayment(Request $request, PaymentService $payments, string $id): JsonResponse
    {
        $this->authorizeAction('edit');
        \Illuminate\Support\Facades\Gate::authorize('payments.manage');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'note' => ['required', 'string', 'max:255']]);
        $payment = $payments->recordManual(Order::findOrFail($id), (string) $data['amount'], $data['note'], $request->user()->id);

        return response()->json(['data' => $payment], 201);
    }

    public function refund(Request $request, PaymentService $payments, string $payment): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('payments.refund');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $payments->refund(Payment::findOrFail($payment), (string) $data['amount'], $data['reason'], $request->user()->id)->load('refunds')]);
    }

    /** Printable invoice data (HTML) for a paid or placed order. */
    public function invoice(string $id)
    {
        $this->authorizeAction('view');
        $order = Order::with('items')->findOrFail($id);

        return response(view('invoice', ['order' => $order])->render())->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
