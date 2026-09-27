<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
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
     * Where an admin can publish a customers figure of their own instead of
     * the counted one. Blank means count.
     */
    public const CUSTOMERS_OVERRIDE_KEY = 'trust.customers_served';

    /**
     * The two figures the public pages lead with: customers with an account,
     * and countries with something on the shelf. Cached fifteen minutes, so a
     * new sign-up is inside the number before anyone reads it twice.
     *
     * @return array{customers: int, countries: int}
     */
    public static function headlineCounts(): array
    {
        $counted = Cache::remember('home:counts:v2', now()->addMinutes(15), fn () => [
            'customers' => (int) User::where('is_guest', false)->count(),
            'countries' => (int) Product::query()
                ->where('is_active', true)
                ->whereNotNull('country_code')
                ->distinct()
                ->count('country_code'),
        ]);

        // Read outside the fifteen-minute cache on purpose: the override is a
        // deliberate act by an admin who is about to go and look at the page,
        // and its own key is dropped from the cache the moment it is saved. It
        // would be odd to type a number and then wait a quarter of an hour to
        // see whether it took.
        return [
            'customers' => self::customersOverride() ?? $counted['customers'],
            'countries' => $counted['countries'],
        ];
    }

    /**
     * The number an admin has chosen to publish as the customers headline, or
     * null to let the real count stand.
     *
     * Anything that is not a number is treated as no answer rather than as
     * zero: a typo in a settings box must not wipe a figure off the public
     * pages. Zero itself is a real answer, and hides the figure, because a
     * site with nothing to boast about should be allowed to say nothing.
     */
    public static function customersOverride(): ?int
    {
        $raw = trim((string) SiteSetting::get(self::CUSTOMERS_OVERRIDE_KEY, ''));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value >= 0 ? $value : null;
    }

    /**
     * A figure short enough for a badge: 1200 reads "1.2K", 3400000 reads
     * "3.4M", below a thousand the number itself. Plain arithmetic, so it
     * needs no intl extension (the framework abbreviation does, and a page
     * that showed live figures returned a 500 on a machine without it).
     */
    public static function abbreviate(int|float $value): string
    {
        $value = (float) $value;

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'K'] as $unit => $suffix) {
            if (abs($value) >= $unit) {
                $short = round($value / $unit, 1);

                return (fmod($short, 1.0) === 0.0 ? number_format($short) : number_format($short, 1)).$suffix;
            }
        }

        return number_format($value);
    }
}
