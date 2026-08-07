<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Tiny facade around SiteSetting for feature flags. Every features.* key
 * stored in the database is on/off and rides through this class so views
 * and controllers can do FeatureFlag::on('checkout') without thinking about
 * the storage layer or the on/off/true/1 normalisation.
 *
 * Reads are cached by SiteSetting::get() (1h), so this is cheap.
 */
class FeatureFlag
{
    /** Currencies considered "truthy" for the on/off toggles. */
    private const TRUTHY = ['on', 'true', '1'];

    /**
     * The ONE canonical map from a category's DB slug to its feature-flag key.
     * The slugs and flag keys diverge (mobile-airtime -> topups, bill-payments ->
     * bills), so every surface - nav, listings, detail pages, and the server-side
     * purchase guard - must resolve the flag through here so "greyed out" in the UI
     * and "blocked" on the server can never disagree.
     *
     * @var array<string, string>
     */
    public const CATEGORY_FLAGS = [
        'gift-cards' => 'gift_cards',
        'esims' => 'esims',
        'mobile-airtime' => 'topups',
        'bill-payments' => 'bills',
        // Coming-soon categories: no products behind them yet, but the flags
        // gate the surfaces that advertise them (homepage cards, admin menu).
        'flights' => 'flights',
        'stays' => 'stays',
    ];

    /**
     * Feature-flag key for a category slug, or null when the category has no gate
     * (a future category without a flag - treated as on).
     */
    public static function forCategory(?string $slug): ?string
    {
        return $slug ? (self::CATEGORY_FLAGS[$slug] ?? null) : null;
    }

    /**
     * Is the category (identified by its DB slug) currently enabled? Ungated
     * categories are always considered on, matching on()'s default-true behaviour.
     */
    public static function categoryEnabled(?string $slug): bool
    {
        $key = self::forCategory($slug);

        return $key === null ? true : self::on($key);
    }

    /**
     * Is the named feature flag turned on? Pass the suffix only:
     *   FeatureFlag::on('checkout')              // reads features.checkout_enabled
     *   FeatureFlag::on('feedback_widget')       // reads features.feedback_widget_enabled
     *
     * The `_enabled` suffix is added automatically. Pass `$default` to control
     * what happens when the row is missing entirely (defaults to true so new
     * features ship as enabled until an admin explicitly turns them off).
     */
    public static function on(string $name, bool $default = true): bool
    {
        $key = str_starts_with($name, 'features.') ? $name : 'features.'.rtrim($name, '_').'_enabled';
        $value = SiteSetting::get($key, $default ? 'on' : 'off');

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), self::TRUTHY, true);
    }

    /** Convenience inverse. */
    public static function off(string $name, bool $default = true): bool
    {
        return ! self::on($name, $default);
    }
}
