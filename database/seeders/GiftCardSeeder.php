<?php

namespace Database\Seeders;

use App\Models\GiftCardBrand;
use App\Models\GiftCardRate;
use Illuminate\Database\Seeder;

class GiftCardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $brands = [
            [
                'name' => 'Apple',
                'currency' => 'USD',
                'is_active' => true,
                'rates' => [
                    ['country_code' => 'US', 'currency' => 'NGN', 'min_value' => 10, 'max_value' => 500, 'rate' => 1300],
                    ['country_code' => 'US', 'currency' => 'XAF', 'min_value' => 10, 'max_value' => 500, 'rate' => 570],
                    ['country_code' => 'GB', 'currency' => 'NGN', 'min_value' => 10, 'max_value' => 500, 'rate' => 1500],
                ],
            ],
            [
                'name' => 'Amazon',
                'currency' => 'USD',
                'is_active' => true,
                'rates' => [
                    ['country_code' => 'US', 'currency' => 'NGN', 'min_value' => 50, 'max_value' => 1000, 'rate' => 1250],
                    ['country_code' => 'US', 'currency' => 'XAF', 'min_value' => 50, 'max_value' => 1000, 'rate' => 550],
                    ['country_code' => 'GB', 'currency' => 'NGN', 'min_value' => 50, 'max_value' => 1000, 'rate' => 1480],
                ],
            ],
            [
                'name' => 'Steam',
                'currency' => 'USD',
                'is_active' => true,
                'rates' => [
                    ['country_code' => 'US', 'currency' => 'NGN', 'min_value' => 20, 'max_value' => 200, 'rate' => 1200],
                    ['country_code' => 'GB', 'currency' => 'XAF', 'min_value' => 20, 'max_value' => 200, 'rate' => 580],
                ],
            ],
        ];

        foreach ($brands as $b) {
            $brand = GiftCardBrand::create([
                'name' => $b['name'],
                'currency' => $b['currency'],
                'is_active' => $b['is_active'],
            ]);

            foreach ($b['rates'] as $rate) {
                GiftCardRate::create([
                    'brand_id' => $brand->id,
                    'country_code' => $rate['country_code'],
                    'currency' => $rate['currency'],
                    'min_value' => $rate['min_value'],
                    'max_value' => $rate['max_value'],
                    'rate' => $rate['rate'],
                    'is_active' => true,
                ]);
            }
        }
    }
}
