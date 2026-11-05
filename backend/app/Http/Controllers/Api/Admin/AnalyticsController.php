<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Task;
use App\Services\Analytics\AnalyticsService;
use App\Services\Analytics\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Read-only, DB-driven reporting. KPIs/charts need analytics.view; widgets also respect each module's own view permission. */
class AnalyticsController extends Controller
{
    public function __construct(private AnalyticsService $analytics) {}

    private function filters(Request $request): array
    {
        $data = $request->validate([
            'range' => ['nullable', Rule::in(DateRange::PRESETS)],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'granularity' => ['nullable', Rule::in(['day', 'month'])],
        ]);

        return [DateRange::resolve($data['range'] ?? null, $data['from'] ?? null, $data['to'] ?? null), $this->analytics->defaultCurrency($data['currency'] ?? null), $data['granularity'] ?? null];
    }

    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('analytics.view');
        [$range, $currency] = $this->filters($request);

        return response()->json(['data' => [
            'range' => ['preset' => $range->preset, 'from' => $range->from->toIso8601String(), 'to' => $range->to->toIso8601String()],
            'currency' => $currency,
            'currencies' => $this->analytics->activeCurrencies(),
            'kpis' => $this->analytics->kpis($range, $currency),
        ]]);
    }

    public function chart(Request $request, string $name): JsonResponse
    {
        Gate::authorize('analytics.view');
        abort_unless(in_array($name, AnalyticsService::CHARTS, true), 404);
        [$range, $currency, $g] = $this->filters($request);

        return response()->json(['data' => $this->analytics->chart($name, $range, $currency, $g)]);
    }

    public function charts(Request $request): JsonResponse
    {
        Gate::authorize('analytics.view');
        [$range, $currency, $g] = $this->filters($request);
        $out = [];
        foreach (AnalyticsService::CHARTS as $name) {
            $out[$name] = $this->analytics->chart($name, $range, $currency, $g);
        }

        return response()->json(['data' => $out]);
    }

    public function export(Request $request, string $name): StreamedResponse
    {
        Gate::authorize('analytics.export');
        abort_unless(in_array($name, AnalyticsService::CHARTS, true), 404);
        [$range, $currency, $g] = $this->filters($request);
        $chart = $this->analytics->chart($name, $range, $currency, $g);
        $series = $chart['series'];
        $cols = $series ? array_keys($series[0]) : ['label', 'value'];

        return response()->streamDownload(function () use ($series, $cols) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $cols);
            foreach ($series as $row) {
                // neutralise spreadsheet formula injection in text cells
                fputcsv($h, array_map(fn ($c) => is_string($c = $row[$c] ?? '') && preg_match('/^[=+\-@\t\r]/', $c) ? "'".$c : $c, $cols));
            }
            fclose($h);
        }, "{$name}-{$range->from->format('Ymd')}-{$range->to->format('Ymd')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Dashboard widgets; each block is included only if the viewer may see that module. */
    public function widgets(Request $request): JsonResponse
    {
        Gate::authorize('dashboard.view');
        $limit = min(max((int) $request->query('limit', 5), 1), 20);
        $w = [];
        if (Gate::allows('orders.view')) {
            $w['recent_orders'] = Order::latest()->limit($limit)->get(['id', 'number', 'customer_name', 'status', 'method', 'currency_code', 'total', 'created_at']);
            $w['top_products'] = $this->analytics->chart('top_products', DateRange::resolve('last_30_days'), $this->analytics->defaultCurrency(null))['series'];
        }
        if (Gate::allows('leads.view')) {
            $w['recent_leads'] = Lead::latest()->limit($limit)->get(['id', 'name', 'company', 'source', 'status', 'priority', 'created_at']);
            $w['popular_services'] = $this->analytics->chart('most_requested_services', DateRange::resolve('last_30_days'), 'EGP')['series'];
        }
        if (Gate::allows('quotations.view')) {
            $w['pending_quotations'] = Quotation::whereIn('status', ['sent', 'viewed'])->latest()->limit($limit)->get(['id', 'number', 'customer_name', 'currency_code', 'total', 'valid_until', 'status']);
        }
        if (Gate::allows('messages.view')) {
            $w['latest_messages'] = ContactMessage::latest()->limit($limit)->get(['id', 'name', 'subject', 'status', 'created_at']);
        }
        if (Gate::allows('tasks.view') || Gate::allows('tasks.view_assigned')) {
            $q = Task::where('status', 'open')->whereNotNull('due_at')->orderBy('due_at');
            if (! Gate::allows('tasks.view')) {
                $q->where('assigned_to', $request->user()->id);
            }
            $w['upcoming_tasks'] = $q->limit($limit)->get(['id', 'title', 'due_at', 'priority', 'assigned_to', 'taskable_type', 'taskable_id']);
        }
        if (Gate::allows('audit.view')) {
            $w['recent_activity'] = AuditLog::with('user:id,name')->latest('created_at')->limit($limit)->get(['id', 'user_id', 'event', 'auditable_type', 'auditable_id', 'created_at']);
        }
        if (Gate::allows('payments.view')) {
            $w['payment_alerts'] = [
                'failed_last_7_days' => Payment::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
                'awaiting_payment_orders' => Order::where('status', 'awaiting_payment')->count(),
                'recent_failed' => Payment::where('status', 'failed')->latest()->limit($limit)->get(['id', 'order_id', 'provider', 'amount', 'currency_code', 'created_at']),
            ];
        }
        if (Gate::allows('products.view')) {
            $w['low_stock'] = Product::where('track_stock', true)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->limit($limit)->get(['id', 'sku', 'name_en', 'name_ar', 'stock_quantity', 'low_stock_threshold']);
        }
        $w['notifications'] = ['unread' => $request->user()->unreadNotifications()->count()];

        return response()->json(['data' => $w]);
    }
}
