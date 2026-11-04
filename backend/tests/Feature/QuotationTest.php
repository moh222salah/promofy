<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Quotation;
use App\Services\Crm\QuotationService;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/api/v1/admin';

    private const PUB = '/api/v1/public';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
    }

    private function body(array $over = []): array
    {
        return array_merge([
            'customer_name' => 'ACME', 'customer_email' => 'buyer@acme.test', 'title' => 'Brand launch', 'currency_code' => 'SAR', 'tax_rate' => 15,
            'valid_until' => now()->addDays(10)->toDateString(), 'payment_terms' => '50% upfront',
            'items' => [
                ['name_ar' => 'هوية', 'name_en' => 'Identity', 'quantity' => 1, 'unit_price' => 5000],
                ['name_ar' => 'إعلانات', 'name_en' => 'Ads', 'quantity' => 3, 'unit_price' => 1000.50, 'discount_percent' => 10],
            ],
        ], $over);
    }

    private function make(array $over = [], string $role = 'sales-manager'): array
    {
        $user = $this->staffWithRole($role);
        $res = $this->actingAs($user)->postJson(self::ADMIN.'/quotations', $this->body($over))->assertCreated();

        return [$user, $res->json('data.id'), $res];
    }

    public function test_totals_are_computed_server_side(): void
    {
        [, , $res] = $this->make();
        // 5000 + 3001.50 = 8001.50 ; discount 10% of 3001.50 = 300.15 ; net 7701.35 ; tax 15% = 1155.20 (round half up) ; total 8856.55
        $res->assertJsonPath('data.subtotal', '8001.50')->assertJsonPath('data.discount_total', '300.15')->assertJsonPath('data.tax_total', '1155.20')->assertJsonPath('data.total', '8856.55');
        $this->assertStringStartsWith('QT-', $res->json('data.number'));
        $this->assertArrayNotHasKey('public_token', $res->json('data'));
    }

    public function test_validation(): void
    {
        $u = $this->staffWithRole('sales-manager');
        $this->actingAs($u)->postJson(self::ADMIN.'/quotations', $this->body(['items' => [['name_ar' => 'x', 'name_en' => 'x', 'quantity' => 0, 'unit_price' => -1]]]))->assertUnprocessable();
        $this->actingAs($u)->postJson(self::ADMIN.'/quotations', $this->body(['valid_until' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('valid_until');
        $this->actingAs($u)->postJson(self::ADMIN.'/quotations', $this->body(['currency_code' => 'XXX']))->assertUnprocessable();
    }

    public function test_only_drafts_are_editable_and_cannot_send_without_items(): void
    {
        [$u, $id] = $this->make();
        $this->actingAs($u)->putJson(self::ADMIN."/quotations/{$id}", ['title' => 'Renamed', 'items' => [['name_ar' => 'ب', 'name_en' => 'B', 'quantity' => 2, 'unit_price' => 100]]])
            ->assertOk()->assertJsonPath('data.subtotal', '200.00');
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->actingAs($u)->putJson(self::ADMIN."/quotations/{$id}", ['title' => 'Tamper'])->assertUnprocessable();
        $this->actingAs($u)->deleteJson(self::ADMIN."/quotations/{$id}")->assertStatus(409);

        [$u2, $empty] = $this->make(['items' => []]);
        $this->actingAs($u2)->postJson(self::ADMIN."/quotations/{$empty}/send")->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_customer_views_and_approves_with_private_token(): void
    {
        [$u, $id] = $this->make();
        $q = Quotation::find($id);
        $this->getJson(self::PUB."/quotations/{$q->number}?token={$q->public_token}")->assertNotFound(); // still draft
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/send")->assertOk();

        $this->getJson(self::PUB."/quotations/{$q->number}")->assertNotFound();
        $this->getJson(self::PUB."/quotations/{$q->number}?token=wrong")->assertNotFound();
        $view = $this->getJson(self::PUB."/quotations/{$q->number}?token={$q->public_token}")->assertOk()->assertJsonPath('data.status', 'viewed');
        $this->assertArrayNotHasKey('assigned_to', $view->json('data'));
        $this->assertNotNull($q->fresh()->viewed_at);

        $this->postJson(self::PUB."/quotations/{$q->number}/decision?token={$q->public_token}", ['decision' => 'rejected'])->assertUnprocessable(); // note required
        $this->postJson(self::PUB."/quotations/{$q->number}/decision?token={$q->public_token}", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('customer', $q->fresh()->decided_by);
        $this->postJson(self::PUB."/quotations/{$q->number}/decision?token={$q->public_token}", ['decision' => 'rejected', 'note' => 'late'])->assertUnprocessable(); // final
        $this->assertTrue(\DB::table('audit_logs')->where('event', 'quotation.approved')->exists());
    }

    public function test_convert_approved_quote_to_order_once(): void
    {
        [$u, $id] = $this->make();
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/convert-to-order")->assertForbidden(); // sales manager has no orders.edit
        $this->actingAs($this->staffWithRole('administrator'))->postJson(self::ADMIN."/quotations/{$id}/convert-to-order")->assertUnprocessable(); // not approved
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/send")->assertOk();
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/approve", ['note' => 'Signed offline'])->assertOk()->assertJsonPath('data.status', 'approved');

        $admin = $this->staffWithRole('administrator');
        $res = $this->actingAs($admin)->postJson(self::ADMIN."/quotations/{$id}/convert-to-order")->assertCreated();
        $order = Order::findOrFail($res->json('data.order_id'));
        $this->assertSame('8856.55', $order->total);
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->paid_at);
        $this->assertCount(2, $order->items);
        $this->actingAs($admin)->postJson(self::ADMIN."/quotations/{$id}/convert-to-order")->assertUnprocessable();
    }

    public function test_permissions_agent_scope_and_approval_rights(): void
    {
        $agent = $this->staffWithRole('sales-agent');
        $mine = $this->actingAs($agent)->postJson(self::ADMIN.'/quotations', $this->body())->assertCreated()->json('data.id');
        $other = $this->staffWithRole('sales-manager');
        $theirs = $this->actingAs($other)->postJson(self::ADMIN.'/quotations', $this->body(['title' => 'Other']))->json('data.id');

        $this->assertCount(1, $this->actingAs($agent)->getJson(self::ADMIN.'/quotations')->json('data'));
        $this->actingAs($agent)->getJson(self::ADMIN."/quotations/{$theirs}")->assertNotFound();
        $this->actingAs($agent)->postJson(self::ADMIN."/quotations/{$mine}/send")->assertForbidden(); // no quotations.send
        $this->actingAs($agent)->postJson(self::ADMIN."/quotations/{$mine}/approve")->assertForbidden();
        $this->actingAs($this->staffWithRole('content-editor'))->getJson(self::ADMIN.'/quotations')->assertForbidden();
        $this->actingAs($this->staffWithRole('finance-manager'))->postJson(self::ADMIN."/quotations/{$theirs}/send")->assertForbidden();
    }

    public function test_expiry_cancel_document_and_export(): void
    {
        [$u, $id] = $this->make();
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/send")->assertOk();
        $this->actingAs($u)->get(self::ADMIN."/quotations/{$id}/document")->assertOk()->assertSee('8856.55');
        $this->actingAs($u)->getJson(self::ADMIN."/quotations/{$id}/link")->assertOk()->assertJsonStructure(['data' => ['url']]);

        Quotation::whereKey($id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->assertSame(1, app(QuotationService::class)->expireOverdue());
        $this->assertSame('expired', Quotation::find($id)->status);
        $this->actingAs($u)->postJson(self::ADMIN."/quotations/{$id}/cancel")->assertUnprocessable();

        [$u2, $id2] = $this->make(['title' => 'Second']);
        $this->actingAs($u2)->postJson(self::ADMIN."/quotations/{$id2}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $csv = $this->actingAs($u2)->get(self::ADMIN.'/quotations/export')->assertOk();
        $this->assertStringContainsString('Brand launch', $csv->streamedContent());
    }
}
