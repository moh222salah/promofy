<?php

namespace App\Services\Analytics;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Quotation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number comes from the database. Money is never converted between currencies:
 * revenue figures are always for ONE currency (the one requested, or the store default).
 */
class AnalyticsService
{
    public const CHARTS = [
        'revenue_over_time', 'orders_over_time', 'leads_trend', 'lead_funnel', 'revenue_by_country', 'revenue_by_product_category',
        'revenue_by_service_category', 'top_products', 'most_requested_services', 'traffic_sources', 'campaign_performance',
        'customer_acquisition', 'payment_status_distribution', 'order_status_distribution',
    ];

    private const PAID_PAYMENT = ['succeeded', 'partially_refunded', 'refunded'];

    // ----------------------------------------------------------------- KPIs
    /** @return array<string, array{value: float|int, previous: float|int, change_percent: ?float}> */
    public function kpis(DateRange $range, string $currency): array
    {
        $now = $this->kpiValues($range, $currency);
        $prev = $this->kpiValues($range->previous(), $currency);
        $out = [];
        foreach ($now as $key => $value) {
            $p = $prev[$key] ?? 0;
            $out[$key] = ['value' => $value, 'previous' => $p, 'change_percent' => $p != 0 ? round(($value - $p) / abs($p) * 100, 1) : null];
        }

        return $out;
    }

    /** @return array<string, float|int> */
    private function kpiValues(DateRange $r, string $currency): array
    {
        $payments = Payment::whereIn('status', self::PAID_PAYMENT)->where('currency_code', $currency)->whereBetween('created_at', [$r->from, $r->to]);
        $revenue = (float) (clone $payments)->sum('amount') - (float) (clone $payments)->sum('refunded_total');
        $paidOrders = Order::whereNotNull('paid_at')->where('currency_code', $currency)->whereBetween('paid_at', [$r->from, $r->to]);
        $leads = Lead::whereBetween('created_at', [$r->from, $r->to]);
        $leadCount = (clone $leads)->count();
        $won = (clone $leads)->where('status', 'won')->count();

        return [
            'total_revenue' => round($revenue, 2),
            'confirmed_payments' => (clone $payments)->where('status', '!=', 'refunded')->count(),
            'total_orders' => Order::whereBetween('created_at', [$r->from, $r->to])->count(),
            'pending_orders' => Order::whereIn('status', ['pending', 'awaiting_payment'])->whereBetween('created_at', [$r->from, $r->to])->count(),
            'new_leads' => $leadCount,
            'qualified_leads' => (clone $leads)->whereIn('status', ['qualified', 'proposal_sent', 'negotiation', 'won'])->count(),
            'conversion_rate' => $leadCount > 0 ? round($won / $leadCount * 100, 1) : 0.0,
            'pending_quotations' => Quotation::whereIn('status', ['sent', 'viewed'])->whereBetween('created_at', [$r->from, $r->to])->count(),
            'active_projects' => Project::whereIn('status', ['planning', 'in_progress', 'under_review'])->whereBetween('created_at', [$r->from, $r->to])->count(),
            'completed_projects' => Project::where('status', 'completed')->whereBetween('completed_at', [$r->from, $r->to])->count(),
            'average_order_value' => round((float) ((clone $paidOrders)->avg('total') ?? 0), 2),
            'customer_growth' => Customer::whereBetween('created_at', [$r->from, $r->to])->count(),
        ];
    }

    // ----------------------------------------------------------------- charts
    /** @return array{chart: string, type: string, series: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function chart(string $name, DateRange $range, string $currency, ?string $granularity = null): array
    {
        $g = $range->granularity($granularity);

        return match ($name) {
            'revenue_over_time' => $this->timeSeries($name, $range, $g, $this->paymentRows($range, $currency), fn ($r) => $r['net'], ['currency' => $currency], 'created_at'),
            'orders_over_time' => $this->timeSeries($name, $range, $g, Order::whereBetween('created_at', [$range->from, $range->to])->get(['created_at'])->map(fn ($o) => ['created_at' => $o->created_at, 'v' => 1]), fn ($r) => 1),
            'leads_trend' => $this->timeSeries($name, $range, $g, Lead::whereBetween('created_at', [$range->from, $range->to])->get(['created_at'])->map(fn ($o) => ['created_at' => $o->created_at]), fn ($r) => 1),
            'customer_acquisition' => $this->timeSeries($name, $range, $g, Customer::whereBetween('created_at', [$range->from, $range->to])->get(['created_at'])->map(fn ($o) => ['created_at' => $o->created_at]), fn ($r) => 1),
            'lead_funnel' => $this->leadFunnel($range),
            'revenue_by_country' => $this->revenueByCountry($range, $currency),
            'revenue_by_product_category' => $this->revenueByProductCategory($range, $currency),
            'revenue_by_service_category' => $this->revenueByServiceCategory($range, $currency),
            'top_products' => $this->topProducts($range, $currency),
            'most_requested_services' => $this->mostRequestedServices($range),
            'traffic_sources' => $this->trafficSources($range),
            'campaign_performance' => $this->campaigns($range, $currency),
            'payment_status_distribution' => $this->distribution($name, Payment::whereBetween('created_at', [$range->from, $range->to]), 'status'),
            'order_status_distribution' => $this->distribution($name, Order::whereBetween('created_at', [$range->from, $range->to]), 'status'),
        };
    }

    private function wrap(string $name, string $type, array $series, array $meta = []): array
    {
        return ['chart' => $name, 'type' => $type, 'series' => $series, 'meta' => $meta];
    }

    /** @return Collection<int, array{created_at: \DateTimeInterface, net: float}> */
    private function paymentRows(DateRange $r, string $currency): Collection
    {
        return Payment::whereIn('status', self::PAID_PAYMENT)->where('currency_code', $currency)->whereBetween('created_at', [$r->from, $r->to])
            ->get(['created_at', 'amount', 'refunded_total'])->map(fn ($p) => ['created_at' => $p->created_at, 'net' => (float) $p->amount - (float) $p->refunded_total]);
    }

    private function timeSeries(string $name, DateRange $range, string $g, Collection $rows, callable $value, array $meta = [], string $field = 'created_at'): array
    {
        $totals = array_fill_keys($range->buckets($g), 0.0);
        foreach ($rows as $row) {
            $key = $range->bucketOf($row[$field], $g);
            if (isset($totals[$key])) {
                $totals[$key] += $value($row);
            }
        }

        return $this->wrap($name, 'line', collect($totals)->map(fn ($v, $k) => ['label' => $k, 'value' => round($v, 2)])->values()->all(), $meta + ['granularity' => $g]);
    }

    private function leadFunnel(DateRange $r): array
    {
        $counts = Lead::whereBetween('created_at', [$r->from, $r->to])->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $order = ['new', 'contacted', 'qualified', 'proposal_sent', 'negotiation', 'won'];
        // cumulative: a lead that reached "won" also passed every earlier stage
        $series = [];
        foreach ($order as $i => $stage) {
            $series[] = ['label' => $stage, 'value' => (int) collect($order)->slice($i)->sum(fn ($s) => $counts[$s] ?? 0)];
        }

        return $this->wrap('lead_funnel', 'funnel', $series, ['lost' => (int) ($counts['lost'] ?? 0), 'note' => 'Each stage counts leads that reached it or later; lost leads are reported separately.']);
    }

    private function revenueByCountry(DateRange $r, string $currency): array
    {
        $rows = Payment::join('orders', 'orders.id', '=', 'payments.order_id')->whereIn('payments.status', self::PAID_PAYMENT)->where('payments.currency_code', $currency)
            ->whereBetween('payments.created_at', [$r->from, $r->to])->get(['orders.country_code', 'payments.amount', 'payments.refunded_total']);
        $by = [];
        foreach ($rows as $row) {
            $by[$row->country_code ?: '—'] = ($by[$row->country_code ?: '—'] ?? 0) + (float) $row->amount - (float) $row->refunded_total;
        }
        arsort($by);

        return $this->wrap('revenue_by_country', 'bar', collect($by)->map(fn ($v, $k) => ['label' => $k, 'value' => round($v, 2)])->values()->all(), ['currency' => $currency]);
    }

    private function paidItems(DateRange $r, string $currency)
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereNull('orders.deleted_at')->whereNotNull('orders.paid_at')->whereNotIn('orders.status', ['refunded', 'cancelled'])->where('orders.currency_code', $currency)
            ->whereBetween('orders.paid_at', [$r->from, $r->to]);
    }

    private function revenueByProductCategory(DateRange $r, string $currency): array
    {
        $rows = $this->paidItems($r, $currency)->leftJoin('product_categories', 'product_categories.id', '=', 'products.product_category_id')
            ->groupBy('product_categories.name_en', 'product_categories.name_ar')->orderByDesc(DB::raw('sum(order_items.line_total)'))
            ->get([DB::raw('coalesce(product_categories.name_en, \'Uncategorised\') as label'), DB::raw('coalesce(product_categories.name_ar, \'بدون تصنيف\') as label_ar'), DB::raw('sum(order_items.line_total) as value')]);

        return $this->wrap('revenue_by_product_category', 'bar', $rows->map(fn ($x) => ['label' => $x->label, 'label_ar' => $x->label_ar, 'value' => round((float) $x->value, 2)])->all(), ['currency' => $currency]);
    }

    private function revenueByServiceCategory(DateRange $r, string $currency): array
    {
        $rows = DB::table('quotation_items')->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')->leftJoin('services', 'services.id', '=', 'quotation_items.service_id')
            ->leftJoin('service_categories', 'service_categories.id', '=', 'services.service_category_id')->whereNull('quotations.deleted_at')->where('quotations.status', 'approved')->where('quotations.currency_code', $currency)
            ->whereBetween('quotations.decided_at', [$r->from, $r->to])->groupBy('service_categories.name_en', 'service_categories.name_ar')->orderByDesc(DB::raw('sum(quotation_items.line_total)'))
            ->get([DB::raw('coalesce(service_categories.name_en, \'Custom items\') as label'), DB::raw('coalesce(service_categories.name_ar, \'بنود مخصصة\') as label_ar'), DB::raw('sum(quotation_items.line_total) as value')]);

        return $this->wrap('revenue_by_service_category', 'bar', $rows->map(fn ($x) => ['label' => $x->label, 'label_ar' => $x->label_ar, 'value' => round((float) $x->value, 2)])->all(),
            ['currency' => $currency, 'basis' => 'approved quotations (pre-tax line totals)']);
    }

    private function topProducts(DateRange $r, string $currency): array
    {
        $rows = $this->paidItems($r, $currency)->whereNotNull('order_items.product_id')->groupBy('order_items.product_id', 'order_items.name_en', 'order_items.name_ar')
            ->orderByDesc(DB::raw('sum(order_items.quantity)'))->limit(10)
            ->get(['order_items.name_en as label', 'order_items.name_ar as label_ar', DB::raw('sum(order_items.quantity) as value'), DB::raw('sum(order_items.line_total) as revenue')]);

        return $this->wrap('top_products', 'bar', $rows->map(fn ($x) => ['label' => $x->label, 'label_ar' => $x->label_ar, 'value' => (int) $x->value, 'revenue' => round((float) $x->revenue, 2)])->all(), ['unit' => 'units sold', 'currency' => $currency]);
    }

    private function mostRequestedServices(DateRange $r): array
    {
        $rows = Lead::join('services', 'services.id', '=', 'leads.service_id')->whereNull('leads.deleted_at')->whereBetween('leads.created_at', [$r->from, $r->to])
            ->groupBy('services.id', 'services.name_en', 'services.name_ar')->orderByDesc(DB::raw('count(*)'))->limit(10)
            ->get(['services.name_en as label', 'services.name_ar as label_ar', DB::raw('count(*) as value')]);

        return $this->wrap('most_requested_services', 'bar', $rows->map(fn ($x) => ['label' => $x->label, 'label_ar' => $x->label_ar, 'value' => (int) $x->value])->all(), ['unit' => 'leads']);
    }

    /** Attributed lead / order sources (what brought customers in), not raw site sessions — those live in GA4. */
    private function trafficSources(DateRange $r): array
    {
        $leads = Lead::whereBetween('created_at', [$r->from, $r->to])->select('source', DB::raw('count(*) as c'))->groupBy('source')->pluck('c', 'source');
        $by = [];
        foreach (Order::whereBetween('created_at', [$r->from, $r->to])->get(['attribution']) as $o) {
            $src = $o->attribution['utm_source'] ?? 'direct';
            $by[$src] = ($by[$src] ?? 0) + 1;
        }
        $labels = collect(array_keys($leads->all()))->merge(array_keys($by))->unique()->values();

        return $this->wrap('traffic_sources', 'bar', $labels->map(fn ($l) => ['label' => $l, 'value' => (int) ($leads[$l] ?? 0) + (int) ($by[$l] ?? 0), 'leads' => (int) ($leads[$l] ?? 0), 'orders' => (int) ($by[$l] ?? 0)])
            ->sortByDesc('value')->values()->all(), ['basis' => 'attributed leads + orders (not site sessions)']);
    }

    private function campaigns(DateRange $r, string $currency): array
    {
        $rows = [];
        foreach (Lead::whereBetween('created_at', [$r->from, $r->to])->get(['attribution', 'status']) as $l) {
            if ($c = $l->attribution['utm_campaign'] ?? null) {
                $rows[$c]['leads'] = ($rows[$c]['leads'] ?? 0) + 1;
                $rows[$c]['won'] = ($rows[$c]['won'] ?? 0) + ($l->status === 'won' ? 1 : 0);
            }
        }
        foreach (Order::whereBetween('created_at', [$r->from, $r->to])->get(['attribution', 'total', 'currency_code', 'paid_at']) as $o) {
            if ($c = $o->attribution['utm_campaign'] ?? null) {
                $rows[$c]['orders'] = ($rows[$c]['orders'] ?? 0) + 1;
                if ($o->paid_at && $o->currency_code === $currency) {
                    $rows[$c]['revenue'] = ($rows[$c]['revenue'] ?? 0) + (float) $o->total;
                }
            }
        }
        $series = collect($rows)->map(fn ($v, $k) => ['label' => $k, 'leads' => $v['leads'] ?? 0, 'won' => $v['won'] ?? 0, 'orders' => $v['orders'] ?? 0, 'revenue' => round($v['revenue'] ?? 0, 2),
            'value' => ($v['leads'] ?? 0) + ($v['orders'] ?? 0)])->sortByDesc('value')->values()->all();

        return $this->wrap('campaign_performance', 'table', $series, ['currency' => $currency, 'basis' => 'utm_campaign captured at submit time']);
    }

    private function distribution(string $name, $query, string $column): array
    {
        $rows = $query->select($column, DB::raw('count(*) as c'))->groupBy($column)->pluck('c', $column);

        return $this->wrap($name, 'pie', $rows->map(fn ($v, $k) => ['label' => $k, 'value' => (int) $v])->values()->all());
    }

    public function defaultCurrency(?string $requested): string
    {
        $code = strtoupper((string) ($requested ?: app(\App\Services\Settings\SettingsService::class)->get('checkout', 'default_currency', 'EGP')));

        return $code;
    }

    /** Currencies that actually have money movement, so the UI only offers meaningful choices. */
    public function activeCurrencies(): array
    {
        return Payment::distinct()->pluck('currency_code')->merge(Order::distinct()->pluck('currency_code'))->unique()->values()->all();
    }
}
