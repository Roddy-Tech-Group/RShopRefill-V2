<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The figures public copy is allowed to state: what the catalogue holds and
 * how many people shop here. Read from the database and cached, never typed
 * into a template, so the dashboard's purchase cards, the homepage counts and
 * the partner page all say the same numbers and all move on their own as the
 * store grows.
 */
final class CatalogFigures
{
    /** @var array<int, string> */
    public const STORES = ['gift-cards', 'esims', 'mobile-airtime', 'bill-payments'];

    /**
     * Per store: how many countries it covers and how many brands, operators
     * or billers it carries, counting active products only. Cached an hour.
     *
     * @return array<string, array{countries: int, brands: int}>
     */
    public static function purchaseStats(): array
    {
        return Cache::remember('dashboard.purchase-stats', now()->addHour(), function (): array {
            $stats = [];

            foreach (self::STORES as $slug) {
                $categoryId = Category::query()->where('slug', $slug)->value('id');
                $base = $categoryId ? Product::query()->where('category_id', $categoryId)->where('is_active', true) : null;

                $stats[$slug] = [
                    'countries' => $base ? (clone $base)->whereNotNull('country_code')->distinct()->count('country_code') : 0,
                    'brands' => $base ? (clone $base)->whereNotNull('brand_key')->distinct()->count('brand_key') : 0,
                ];
            }

            return $stats;
        });
    }

    /**
     * The two figures the public pages lead with: customers with an account,
     * and countries with something on the shelf. Cached fifteen minutes, so a
     * new sign-up is inside the number before anyone reads it twice.
     *
     * @return array{customers: int, countries: int}
     */
    public static function headlineCounts(): array
    {
        return Cache::remember('home:counts:v2', now()->addMinutes(15), fn () => [
            'customers' => (int) User::where('is_guest', false)->count(),
            'countries' => (int) Product::query()
                ->where('is_active', true)
                ->whereNotNull('country_code')
                ->distinct()
                ->count('country_code'),
        ]);
    }
}
