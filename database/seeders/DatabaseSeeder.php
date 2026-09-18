<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Idempotent — every seeder called here
     * uses updateOrCreate / firstOrCreate, so re-running is safe in any env.
     */
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            SettingsSeeder::class,
            CurrencyRateSeeder::class,
            BlogPostSeeder::class,
            PressArticleSeeder::class,
            ReviewSeeder::class,
            FaqSeeder::class,
            GiftCardFaqSeeder::class,
            EsimFaqSeeder::class,
        ]);
    }
}
