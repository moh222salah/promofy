<?php

namespace Tests\Feature\Admin;

use App\Models\ExchangeRate;
use Database\Seeders\MarketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsAndMarketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_expose_defaults_but_never_private_keys(): void
    {
        $this->getJson('/api/v1/public/settings')->assertOk()
            ->assertJsonPath('data.site.color_accent', '#0E41FE')
            ->assertJsonMissingPath('data.security')
            ->assertJsonMissingPath('data.checkout.download_link_hours');
    }

    public function test_settings_update_is_validated_permissioned_and_audited(): void
    {
        $admin = $this->staffWithRole('administrator');
        $editor = $this->staffWithRole('content-editor');

        $this->actingAs($editor)->putJson('/api/v1/admin/settings/site', ['values' => ['maintenance_mode' => true]])->assertForbidden();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/site', ['values' => ['color_accent' => 'blue']])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/site', ['values' => ['nope' => 1]])->assertStatus(422);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/site', ['values' => ['color_accent' => '#112233']])
            ->assertOk()->assertJsonPath('data.color_accent', '#112233');
        $this->getJson('/api/v1/public/settings')->assertJsonPath('data.site.color_accent', '#112233');
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.updated']);
    }

    public function test_checkout_settings_require_payment_permission(): void
    {
        $marketing = $this->staffWithRole('marketing-manager');
        $finance = $this->staffWithRole('finance-manager');

        $this->actingAs($marketing)->putJson('/api/v1/admin/settings/checkout', ['values' => ['online_payment_enabled' => true]])->assertForbidden();
        $this->actingAs($finance)->putJson('/api/v1/admin/settings/checkout', ['values' => ['online_payment_enabled' => true]])->assertOk();
    }

    public function test_markets_seed_and_exchange_rates_are_never_assumed(): void
    {
        $this->seed(MarketsSeeder::class);
        $this->getJson('/api/v1/public/markets')->assertOk()->assertJsonCount(7, 'data.countries')->assertJsonCount(8, 'data.currencies');

        $this->assertNull(ExchangeRate::latest('EGP', 'SAR'));

        $finance = $this->staffWithRole('finance-manager');
        $this->actingAs($finance)->postJson('/api/v1/admin/exchange-rates', ['base_currency' => 'EGP', 'quote_currency' => 'SAR', 'rate' => '0.0760'])->assertCreated();
        $this->assertSame('0.07600000', ExchangeRate::latest('EGP', 'SAR')->rate);

        $this->actingAs($finance)->postJson('/api/v1/admin/exchange-rates', ['base_currency' => 'EGP', 'quote_currency' => 'EGP', 'rate' => 1])->assertStatus(422);
    }
}
