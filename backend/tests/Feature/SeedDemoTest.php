<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_is_tagged_idempotent_and_fully_purgeable(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\MarketsSeeder::class);
        $this->seed(\Database\Seeders\ContentSeeder::class);
        User::factory()->create(['type' => User::TYPE_STAFF]);
        $real = Order::count();

        $this->artisan('promofy:seed-demo')->assertSuccessful();
        $this->artisan('promofy:seed-demo')->assertSuccessful(); // re-run replaces, never duplicates
        $this->assertSame(76, Order::where('number', 'like', 'DEMO-%')->count());
        $this->assertSame(0, Order::where('number', 'not like', 'DEMO-%')->count());

        $this->artisan('promofy:seed-demo --purge')->assertSuccessful();
        foreach (['orders', 'payments', 'order_items', 'leads', 'quotations', 'quotation_items', 'projects', 'milestones', 'tasks', 'customers', 'contact_messages', 'products', 'product_categories', 'notifications'] as $t) {
            $this->assertSame(0, \DB::table($t)->count(), "$t not purged");
        }
        $this->assertSame($real, Order::count());
    }

    public function test_refuses_in_production_without_force(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('promofy:seed-demo')->assertFailed();
    }
}
