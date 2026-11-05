<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Settings\SettingsService;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    private const PUB = '/api/v1/public';

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
        Storage::fake('local');
        $s = app(SettingsService::class);
        $s->update('checkout', ['whatsapp_order_enabled' => true, 'online_payment_enabled' => true, 'tax_rate_percent' => 0]);
        $s->update('payments', ['gateway' => 'generic', 'gateway_webhook_secret' => self::SECRET, 'gateway_checkout_url' => 'https://pay.example.test/checkout']);
    }

    /** Real flow: product → cart → online checkout → signed webhook → paid order. */
    private function paidOrder(Product $p, int $qty, string $country, array $attribution = []): void
    {
        $cart = $this->postJson(self::PUB.'/cart/items', ['product_id' => $p->id, 'quantity' => $qty])->json('data.token');
        $res = $this->postJson(self::PUB.'/checkout', ['cart_token' => $cart, 'method' => 'online', 'name' => 'Sara', 'email' => 's'.uniqid().'@example.com', 'phone' => '+201111111111', 'country' => $country, 'consent' => true, 'attribution' => $attribution])->assertCreated();
        $ref = $res->json('data.payment.reference');
        $amount = Payment::where('provider_reference', $ref)->value('amount');
        $body = json_encode(['event_id' => 'e-'.uniqid(), 'reference' => $ref, 'status' => 'succeeded', 'amount' => $amount, 'currency' => 'EGP']);
        $this->call('POST', self::PUB.'/payments/webhook/generic', [], [], [], ['HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    }

    private function product(string $sku, int $price): Product
    {
        $res = $this->actingAs($this->staffWithRole('content-manager'))->postJson(self::ADMIN.'/products', ['type' => 'digital_download', 'sku' => $sku, 'name_ar' => 'منتج', 'name_en' => "P-$sku", 'price' => $price, 'currency_code' => 'EGP', 'status' => 'published'])->assertCreated();
        $this->app['auth']->forgetGuards();

        return Product::findOrFail($res->json('data.id'));
    }

    public function test_kpis_and_charts_reflect_real_records_and_refunds(): void
    {
        $a = $this->product('A', 100);
        $b = $this->product('B', 50);
        $this->paidOrder($a, 2, 'EG', ['utm_source' => 'instagram', 'utm_campaign' => 'launch']);
        $this->paidOrder($b, 1, 'SA');
        $this->postJson(self::PUB.'/project-requests', ['name' => 'Omar', 'email' => 'o@example.com', 'phone' => '+966500000000', 'country' => 'SA', 'description' => 'Need ads please', 'consent' => true, 'attribution' => ['utm_source' => 'google', 'utm_campaign' => 'launch']])->assertCreated();
        Lead::first()->forceFill(['status' => 'won'])->save();

        $finance = $this->staffWithRole('finance-manager');
        $this->actingAs($finance);
        $k = $this->getJson(self::ADMIN.'/analytics/overview?range=last_30_days&currency=EGP')->assertOk()->json('data.kpis');
        $this->assertEquals(250, $k['total_revenue']['value']);
        $this->assertSame(2, $k['confirmed_payments']['value']);
        $this->assertSame(2, $k['total_orders']['value']);
        $this->assertEquals(125, $k['average_order_value']['value']);
        $this->assertSame(1, $k['new_leads']['value']);
        $this->assertEquals(100.0, $k['conversion_rate']['value']);

        $rev = $this->getJson(self::ADMIN.'/analytics/charts/revenue_over_time?range=last_7_days&currency=EGP')->assertOk()->json('data');
        $this->assertCount(7, $rev['series']);                       // empty days are present as zero
        $this->assertEquals(250, collect($rev['series'])->sum('value'));

        $byCountry = collect($this->getJson(self::ADMIN.'/analytics/charts/revenue_by_country?currency=EGP')->json('data.series'))->pluck('value', 'label');
        $this->assertEquals(200, $byCountry['EG']);
        $this->assertEquals(50, $byCountry['SA']);

        $top = $this->getJson(self::ADMIN.'/analytics/charts/top_products?currency=EGP')->json('data.series');
        $this->assertSame('P-A', $top[0]['label']);
        $this->assertSame(2, $top[0]['value']);

        $campaign = collect($this->getJson(self::ADMIN.'/analytics/charts/campaign_performance?currency=EGP')->json('data.series'))->firstWhere('label', 'launch');
        $this->assertSame(1, $campaign['leads']);
        $this->assertSame(1, $campaign['orders']);

        $funnel = collect($this->getJson(self::ADMIN.'/analytics/charts/lead_funnel')->json('data.series'))->pluck('value', 'label');
        $this->assertSame(1, $funnel['new']);
        $this->assertSame(1, $funnel['won']);

        $all = $this->getJson(self::ADMIN.'/analytics/charts?currency=EGP')->assertOk()->json('data');
        $this->assertCount(14, $all);

        // refunds reduce net revenue
        $order = Order::where('total', 200)->firstOrFail();
        $this->postJson(self::ADMIN.'/payments/'.$order->payments()->firstOrFail()->id.'/refund', ['amount' => '50', 'reason' => 'partial'])->assertOk();
        $this->assertEquals(200, $this->getJson(self::ADMIN.'/analytics/overview?currency=EGP')->json('data.kpis.total_revenue.value'));
    }

    public function test_period_comparison_custom_range_and_validation(): void
    {
        $this->actingAs($this->staffWithRole('finance-manager'));
        $k = $this->getJson(self::ADMIN.'/analytics/overview?range=today')->assertOk()->json('data.kpis.total_orders');
        $this->assertSame(0, $k['value']);
        $this->assertNull($k['change_percent']);                      // no division by zero

        $this->getJson(self::ADMIN.'/analytics/overview?range=custom&from=2026-01-01&to=2026-01-31')->assertOk();
        $this->getJson(self::ADMIN.'/analytics/overview?range=custom&from=2026-02-01&to=2026-01-01')->assertUnprocessable();
        $this->getJson(self::ADMIN.'/analytics/overview?range=custom')->assertUnprocessable();
        $this->getJson(self::ADMIN.'/analytics/overview?range=bogus')->assertUnprocessable();
        $this->getJson(self::ADMIN.'/analytics/charts/not_a_chart')->assertNotFound();
        foreach (['yesterday', 'last_90_days', 'this_month', 'last_month', 'this_year'] as $r) {
            $this->getJson(self::ADMIN."/analytics/overview?range=$r")->assertOk();
        }
    }

    public function test_permissions_widgets_scoping_and_csv_export(): void
    {
        $this->getJson(self::ADMIN.'/analytics/overview')->assertUnauthorized();

        $this->actingAs($this->staffWithRole('content-editor'));
        $this->getJson(self::ADMIN.'/analytics/overview')->assertForbidden();
        $this->getJson(self::ADMIN.'/analytics/charts')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staffWithRole('sales-agent'));
        $w = $this->getJson(self::ADMIN.'/dashboard/widgets')->assertOk()->json('data');
        $this->assertArrayNotHasKey('recent_orders', $w);             // no orders.view → block omitted
        $this->assertArrayNotHasKey('payment_alerts', $w);
        $this->getJson(self::ADMIN.'/analytics/charts/revenue_over_time/export')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staffWithRole('super-admin'));
        $w = $this->getJson(self::ADMIN.'/dashboard/widgets')->assertOk()->json('data');
        foreach (['recent_orders', 'recent_leads', 'pending_quotations', 'latest_messages', 'top_products', 'popular_services', 'upcoming_tasks', 'recent_activity', 'payment_alerts', 'low_stock', 'notifications'] as $k) {
            $this->assertArrayHasKey($k, $w);
        }
        $csv = $this->get(self::ADMIN.'/analytics/charts/order_status_distribution/export')->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
    }
}
