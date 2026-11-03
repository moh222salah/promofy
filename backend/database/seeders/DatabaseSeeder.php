<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Domain seeders (service catalog, markets, settings…) are registered here as each domain lands.
     * No demo customers, orders, testimonials or case studies are ever seeded: all business
     * records come from real use. Create the first admin with `php artisan promofy:create-admin`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
