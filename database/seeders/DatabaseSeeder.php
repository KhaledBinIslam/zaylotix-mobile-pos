<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BusinessTypeSeeder::class,
            FeatureSeeder::class,
            AdminSeeder::class,
            DemoShopSeeder::class,
            MedicineCatalogSeeder::class,
        ]);
    }
}
