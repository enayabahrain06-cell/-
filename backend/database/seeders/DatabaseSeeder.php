<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Base seeders: reference data only, safe in production and idempotent.
 * Demo accounts and sample data live in DemoSeeder:  php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            MessageTemplateSeeder::class,
            QuranSurahSeeder::class,
            BadgeSeeder::class,
        ]);
    }
}
