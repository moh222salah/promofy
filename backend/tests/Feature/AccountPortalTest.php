<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketsSeeder::class);
        Storage::fake('local');
        app(SettingsService::class)->update('checkout', ['whatsapp_order_enabled' => true]);
        app(SettingsService::class)->update('contact', ['whatsapp_number' => '+201000000000']);
    }

    private function customer(string $email, bool $verified): User
    {
        $u = User::factory()->create(['email' => $email, 'type' => 'customer', 'email_verified_at' => $verified ? now() : null])->fresh();

        return $u;
    }

    private function order(string $email, ?User $as = null): string
    {
        $res = $this->actingAs($this->staffWithRole('content-manager'))->postJson('/api/v1/admin/products', ['type' => 'digital_download', 'sku' => 'S'.uniqid(), 'name_ar' => 'منتج', 'name_en' => 'P', 'price' => 10, 'currency_code' => 'EGP', 'status' => 'published'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $cart = $this->postJson('/api/v1/public/cart/items', ['product_id' => Product::findOrFail($res->json('data.id'))->id, 'quantity' => 1])->json('data.token');
        if ($as) {
            $this->actingAs($as);
        }
        $out = $this->withHeaders(['X-Cart-Token' => $cart])->postJson('/api/v1/public/checkout', ['cart_token' => $cart, 'method' => 'whatsapp', 'name' => 'N', 'email' => $email, 'phone' => '+201111111111', 'country' => 'EG', 'consent' => true])->assertCreated();
        $this->app['auth']->forgetGuards();

        return $out->json('data.order.number');
    }

    public function test_customer_sees_only_own_orders_and_unverified_email_does_not_expose_guest_orders(): void
    {
        $this->getJson('/api/v1/auth/account/orders')->assertUnauthorized();

        $verified = $this->customer('me@example.com', true);
        $unverified = $this->customer('new@example.com', false);
        $mine = $this->order('me@example.com', $verified);
        $guestSameEmail = $this->order('me@example.com');
        $someoneElse = $this->order('other@example.com');
        $guestUnverified = $this->order('new@example.com');

        $this->actingAs($verified);
        $numbers = collect($this->getJson('/api/v1/auth/account/orders')->assertOk()->json('data'))->pluck('number');
        $this->assertTrue($numbers->contains($mine));
        $this->assertTrue($numbers->contains($guestSameEmail));      // verified email claims earlier guest orders
        $this->assertFalse($numbers->contains($someoneElse));
        $detail = $this->getJson("/api/v1/auth/account/orders/$mine")->assertOk()->json('data');
        $this->assertNotEmpty($detail['lookup_token']);
        $this->getJson("/api/v1/auth/account/orders/$someoneElse")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->actingAs($unverified);
        $this->assertCount(0, $this->getJson('/api/v1/auth/account/orders')->json('data'));
        $this->getJson("/api/v1/auth/account/orders/$guestUnverified")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/auth/account/quotations')->json('data'));
    }
}
