<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFile;
use App\Services\Settings\SettingsService;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreTest extends TestCase
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
        $s->update('checkout', ['whatsapp_order_enabled' => true, 'online_payment_enabled' => true, 'tax_rate_percent' => 14]);
        $s->update('contact', ['whatsapp_number' => '+201000000000']);
        $s->update('payments', ['gateway' => 'generic', 'gateway_webhook_secret' => self::SECRET, 'gateway_checkout_url' => 'https://pay.example.test/checkout']);
    }

    private function makeProduct(array $over = []): Product
    {
        $manager = $this->staffWithRole('content-manager');
        $res = $this->actingAs($manager)->postJson(self::ADMIN.'/products', array_merge([
            'type' => 'digital_download', 'sku' => 'TPL-001', 'name_ar' => 'قالب', 'name_en' => 'Template', 'price' => 100, 'currency_code' => 'EGP',
            'status' => 'published',
        ], $over))->assertCreated();

        return Product::findOrFail($res->json('data.id'));
    }

    private function cartWith(Product $p, int $qty = 1, ?int $variantId = null): string
    {
        $res = $this->postJson(self::PUB.'/cart/items', ['product_id' => $p->id, 'quantity' => $qty, 'variant_id' => $variantId])->assertOk();

        return $res->json('data.token');
    }

    private function checkoutBody(string $token, string $method = 'whatsapp'): array
    {
        return ['cart_token' => $token, 'method' => $method, 'name' => 'Sara', 'email' => 'sara@example.com', 'phone' => '+201111111111', 'country' => 'EG', 'consent' => true];
    }

    private function sign(array $payload): array
    {
        $body = json_encode($payload);

        return [$body, ['X-Signature' => hash_hmac('sha256', $body, self::SECRET), 'Content-Type' => 'application/json']];
    }

    private function webhook(array $payload, ?string $signature = null)
    {
        [$body, $headers] = $this->sign($payload);
        if ($signature !== null) {
            $headers['X-Signature'] = $signature;
        }

        return $this->call('POST', self::PUB.'/payments/webhook/generic', [], [], [], $this->transform($headers), $body);
    }

    private function transform(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $out['CONTENT_TYPE'] = 'application/json';

        return $out;
    }

    public function test_product_crud_variants_and_validation(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 10, 'discount_price' => 80]);
        $this->assertSame('template', $p->slug_en);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $p->id, 'reason' => 'initial', 'quantity_after' => 10]);

        $manager = $this->staffWithRole('content-manager');
        $this->actingAs($manager)->postJson(self::ADMIN.'/products', ['type' => 'digital_download', 'sku' => 'TPL-001', 'name_ar' => 'x', 'name_en' => 'x', 'price' => 5, 'currency_code' => 'EGP'])
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->actingAs($manager)->postJson(self::ADMIN.'/products', ['type' => 'digital_download', 'sku' => 'B', 'name_ar' => 'x', 'name_en' => 'x', 'price' => 5, 'discount_price' => 9, 'currency_code' => 'EGP'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_price');

        $v = $this->actingAs($manager)->putJson(self::ADMIN."/products/{$p->id}", ['has_variants' => true, 'variants' => [
            ['sku' => 'TPL-001-S', 'name_ar' => 'صغير', 'name_en' => 'Small', 'stock_quantity' => 3], ['sku' => 'TPL-001-L', 'name_ar' => 'كبير', 'name_en' => 'Large', 'price' => 150],
        ]])->assertOk();
        $this->assertCount(2, $v->json('data.variants'));
        // stock edits through update are ignored; only the inventory endpoint changes it
        $this->actingAs($manager)->putJson(self::ADMIN."/products/{$p->id}", ['stock_quantity' => 999])->assertOk();
        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    public function test_stock_adjustment_history_and_low_stock(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 3, 'low_stock_threshold' => 5]);
        $m = $this->staffWithRole('content-manager');
        $this->actingAs($m)->postJson(self::ADMIN."/products/{$p->id}/stock", ['change' => -1, 'reason' => 'adjustment', 'note' => 'damaged'])->assertOk()->assertJsonPath('data.quantity', 2)->assertJsonPath('data.low', true);
        $this->actingAs($m)->postJson(self::ADMIN."/products/{$p->id}/stock", ['change' => -50, 'reason' => 'adjustment'])->assertUnprocessable();
        $this->assertCount(2, $this->actingAs($m)->getJson(self::ADMIN."/products/{$p->id}/movements")->json('data'));
        $this->assertSame($p->id, $this->actingAs($m)->getJson(self::ADMIN.'/products/low-stock')->json('data.0.id'));
    }

    public function test_public_catalog_hides_unpublished_and_private_fields(): void
    {
        $live = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 4]);
        $this->makeProduct(['sku' => 'DRAFT-1', 'name_en' => 'Hidden', 'name_ar' => 'مخفي', 'status' => 'draft']);
        $list = $this->getJson(self::PUB.'/store/products')->assertOk();
        $this->assertSame([$live->slug_en], collect($list->json('data'))->pluck('slug_en')->all());
        $this->assertArrayNotHasKey('stock_quantity', $list->json('data.0'));
        $this->getJson(self::PUB.'/store/products/hidden')->assertNotFound();
        $this->getJson(self::PUB.'/store/products?search=templ')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::PUB.'/store/products?search=nothing')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cart_totals_are_computed_server_side_with_tax_and_discount(): void
    {
        $p = $this->makeProduct(['price' => 100, 'discount_price' => 80]);
        $res = $this->postJson(self::PUB.'/cart/items', ['product_id' => $p->id, 'quantity' => 2, 'price' => 1])->assertOk();
        $t = $res->json('data.totals');
        $this->assertSame('200.00', $t['subtotal']);
        $this->assertSame('40.00', $t['discount_total']);
        $this->assertSame('22.40', $t['tax_total']); // 14% of 160
        $this->assertSame('182.40', $t['total']);
    }

    public function test_cart_rejects_overselling_variant_requirements_and_mixed_currency(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 2]);
        $this->postJson(self::PUB.'/cart/items', ['product_id' => $p->id, 'quantity' => 3])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $withVar = $this->makeProduct(['sku' => 'V1', 'name_en' => 'Var', 'name_ar' => 'ف', 'has_variants' => true, 'variants' => [['sku' => 'V1-A', 'name_ar' => 'أ', 'name_en' => 'A']]]);
        $this->postJson(self::PUB.'/cart/items', ['product_id' => $withVar->id])->assertUnprocessable()->assertJsonValidationErrors('variant_id');

        $usd = $this->makeProduct(['sku' => 'U1', 'name_en' => 'Usd', 'name_ar' => 'د', 'currency_code' => 'USD']);
        $token = $this->cartWith($this->makeProduct(['sku' => 'E1', 'name_en' => 'Egp', 'name_ar' => 'ج']));
        $this->withHeader('X-Cart-Token', $token)->postJson(self::PUB.'/cart/items', ['product_id' => $usd->id])->assertUnprocessable();
    }

    public function test_whatsapp_checkout_creates_unpaid_order_and_decrements_stock(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 5]);
        $token = $this->cartWith($p, 2);
        $res = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($token))->assertCreated();

        $this->assertSame('pending', $res->json('data.order.status'));
        $this->assertNull(Order::first()->paid_at);
        $this->assertStringContainsString('https://wa.me/201000000000?text=', $res->json('data.whatsapp.url'));
        $this->assertStringContainsString('Total:', $res->json('data.whatsapp.message'));
        $this->assertSame(3, $p->fresh()->stock_quantity);
        $this->assertSame(0, DB::table('payments')->count()); // never auto-paid
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($token))->assertUnprocessable(); // cart was consumed
    }

    public function test_checkout_validation_and_disabled_methods(): void
    {
        $p = $this->makeProduct();
        $token = $this->cartWith($p);
        $this->postJson(self::PUB.'/checkout', ['cart_token' => $token, 'method' => 'whatsapp'])->assertUnprocessable();
        app(SettingsService::class)->update('checkout', ['whatsapp_order_enabled' => false]);
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($token))->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->makeProduct(['sku' => 'NOWA', 'name_en' => 'No wa', 'name_ar' => 'ن', 'allow_whatsapp_order' => false]);
        app(SettingsService::class)->update('checkout', ['whatsapp_order_enabled' => true]);
        $other = $this->cartWith(Product::where('sku', 'NOWA')->first());
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($other))->assertUnprocessable()->assertJsonValidationErrors('method');
    }

    public function test_online_payment_only_confirmed_by_signed_webhook(): void
    {
        $p = $this->makeProduct();
        $res = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p), 'online'))->assertCreated();
        $this->assertSame('awaiting_payment', $res->json('data.order.status'));
        $this->assertStringStartsWith('https://pay.example.test/checkout?', $res->json('data.payment.redirect_url'));
        $ref = $res->json('data.payment.reference');
        $payment = Payment::where('provider_reference', $ref)->firstOrFail();
        $good = ['event_id' => 'evt-1', 'reference' => $ref, 'status' => 'succeeded', 'amount' => $payment->amount, 'currency' => 'EGP'];

        // forged signature / no signature → rejected, nothing changes
        $this->webhook($good, 'deadbeef')->assertUnauthorized();
        $this->assertNull(Order::first()->paid_at);
        $this->assertSame('pending', $payment->fresh()->status);

        // amount mismatch → payment fails, order not paid
        $this->webhook(['event_id' => 'evt-bad', 'amount' => '1.00'] + $good)->assertOk()->assertJsonPath('outcome', 'mismatch');
        $this->assertNull(Order::first()->paid_at);

        $ref2 = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p), 'online'))->json('data.payment.reference');
        $p2 = Payment::where('provider_reference', $ref2)->first();
        $ok = ['event_id' => 'evt-2', 'reference' => $ref2, 'status' => 'succeeded', 'amount' => $p2->amount, 'currency' => 'EGP'];
        $this->webhook($ok)->assertOk()->assertJsonPath('outcome', 'confirmed');
        $this->assertSame('paid', Order::find($p2->order_id)->status);
        $this->assertNotNull($p2->fresh()->verified_at);

        // replay is idempotent
        $this->webhook($ok)->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->assertSame(1, DB::table('payments')->where('status', 'succeeded')->count());
        $this->assertTrue(DB::table('audit_logs')->where('event', 'payment.confirmed')->exists());
    }

    public function test_failed_provider_payment_leaves_order_unpaid(): void
    {
        $p = $this->makeProduct();
        $ref = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p), 'online'))->json('data.payment.reference');
        $this->webhook(['event_id' => 'f1', 'reference' => $ref, 'status' => 'failed', 'amount' => '1', 'currency' => 'EGP'])->assertOk()->assertJsonPath('outcome', 'failed');
        $this->assertNull(Order::first()->paid_at);
        $this->assertSame('failed', Payment::first()->status);
    }

    public function test_digital_downloads_are_gated_by_payment_and_limited(): void
    {
        $p = $this->makeProduct();
        $m = $this->staffWithRole('content-manager');
        $file = UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->actingAs($m)->post(self::ADMIN."/products/{$p->id}/files", ['file' => $file], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.path');
        $this->assertSame(1, ProductFile::count());

        $res = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p)))->assertCreated();
        $number = $res->json('data.order.number');
        $token = $res->json('data.lookup_token');

        $this->withHeader('X-Order-Token', 'wrong')->postJson(self::PUB."/orders/{$number}/downloads")->assertNotFound();
        $this->withHeader('X-Order-Token', $token)->postJson(self::PUB."/orders/{$number}/downloads")->assertForbidden(); // unpaid

        $finance = $this->staffWithRole('finance-manager');
        $order = Order::where('number', $number)->first();
        $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => $order->total, 'note' => 'Bank transfer #123'])->assertCreated();

        $links = $this->withHeader('X-Order-Token', $token)->postJson(self::PUB."/orders/{$number}/downloads")->assertOk()->json('data');
        $this->assertCount(1, $links);
        $raw = basename($links[0]['url']);
        $this->get(self::PUB."/downloads/{$raw}")->assertOk();
        DB::table('download_tokens')->update(['attempts' => 5]);
        $this->get(self::PUB."/downloads/{$raw}")->assertNotFound();
        $this->get(self::PUB.'/downloads/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_manual_payment_requires_permission_and_exact_amount_and_is_audited(): void
    {
        $p = $this->makeProduct();
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p)))->assertCreated();
        $order = Order::first();

        $sales = $this->staffWithRole('sales-manager');
        $this->actingAs($sales)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => $order->total, 'note' => 'x'])->assertForbidden();

        $finance = $this->staffWithRole('finance-manager');
        $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => '1', 'note' => 'x'])->assertUnprocessable();
        $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => $order->total, 'note' => 'Cash'])->assertCreated();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertTrue(DB::table('audit_logs')->where('event', 'payment.manual_recorded')->exists());
        $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => $order->total, 'note' => 'again'])->assertUnprocessable();
    }

    public function test_status_workflow_cancel_restocks_and_paid_cannot_be_forced(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 5]);
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p, 2)))->assertCreated();
        $order = Order::first();
        $admin = $this->staffWithRole('administrator');

        $this->actingAs($admin)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'paid'])->assertUnprocessable();
        $this->actingAs($admin)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'completed'])->assertUnprocessable(); // pending → completed not allowed
        $this->actingAs($admin)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
        $this->actingAs($admin)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'cancelled', 'note' => 'customer asked'])->assertOk();
        $this->assertSame(5, $p->fresh()->stock_quantity);
        $this->assertSame(['pending', 'processing', 'cancelled'], DB::table('order_status_history')->orderBy('id')->pluck('to_status')->all());

        $support = $this->staffWithRole('support-agent');
        $this->actingAs($support)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'processing'])->assertForbidden();
        $this->actingAs($support)->getJson(self::ADMIN.'/orders')->assertOk();
    }

    public function test_refund_flow_restocks_and_blocks_over_refund(): void
    {
        $p = $this->makeProduct(['track_stock' => true, 'stock_quantity' => 5]);
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p)))->assertCreated();
        $order = Order::first();
        $finance = $this->staffWithRole('finance-manager');
        $payment = $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/payments", ['amount' => $order->total, 'note' => 'Cash'])->json('data');

        $this->actingAs($finance)->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'cancelled'])->assertForbidden(); // finance has no cancel right
        $this->actingAs($this->staffWithRole('administrator'))->postJson(self::ADMIN."/orders/{$order->id}/status", ['status' => 'cancelled'])->assertUnprocessable(); // paid orders are refunded, not cancelled
        $this->actingAs($finance)->postJson(self::ADMIN."/payments/{$payment['id']}/refund", ['amount' => '10', 'reason' => 'partial'])->assertOk()->assertJsonPath('data.status', 'partially_refunded');
        $this->actingAs($finance)->postJson(self::ADMIN."/payments/{$payment['id']}/refund", ['amount' => '9999', 'reason' => 'too much'])->assertUnprocessable();
        $remaining = number_format((float) $order->total - 10, 2, '.', '');
        $this->actingAs($finance)->postJson(self::ADMIN."/payments/{$payment['id']}/refund", ['amount' => $remaining, 'reason' => 'rest'])->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(5, $p->fresh()->stock_quantity);

        $content = $this->staffWithRole('content-manager');
        $this->actingAs($content)->postJson(self::ADMIN."/payments/{$payment['id']}/refund", ['amount' => '1', 'reason' => 'x'])->assertForbidden();
    }

    public function test_order_tracking_needs_the_private_token(): void
    {
        $p = $this->makeProduct();
        $res = $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p)))->assertCreated();
        $number = $res->json('data.order.number');
        $this->getJson(self::PUB."/orders/{$number}")->assertNotFound();
        $this->withHeader('X-Order-Token', $res->json('data.lookup_token'))->getJson(self::PUB."/orders/{$number}")->assertOk()->assertJsonPath('data.number', $number);
        $this->assertArrayNotHasKey('lookup_token', Order::first()->toArray());
    }

    public function test_invoice_and_export_work_for_authorised_staff(): void
    {
        $p = $this->makeProduct();
        $this->postJson(self::PUB.'/checkout', $this->checkoutBody($this->cartWith($p)))->assertCreated();
        $order = Order::first();
        $finance = $this->staffWithRole('finance-manager');
        $this->actingAs($finance)->get(self::ADMIN."/orders/{$order->id}/invoice")->assertOk()->assertSee($order->number);
        $csv = $this->actingAs($finance)->get(self::ADMIN.'/orders/export')->assertOk();
        $this->assertStringContainsString($order->number, $csv->streamedContent());
    }
}
