<?php

namespace App\Support;

/**
 * The languages a visitor can choose, resolved from config/localization.php.
 *
 * Every one of them is rendered by Laravel from its own lang/{code} directory,
 * so a language change is a page render, not a repaint. The browser translate
 * widget this used to arbitrate for is gone: it cost a third-party request on
 * every page load, rewrote the document after it had already painted, and
 * could reach languages the picker did not offer.
 *
 * Everything that needs the list resolves through here so there is exactly one
 * of it. The picker used to hold its own array of display names and the engine
 * its own map of eighty-eight, which is how a visitor could be auto-detected
 * into a language the picker could not show back to them.
 */
class Languages
{
    /**
     * Every offered language, in picker order, keyed by locale.
     *
     * @return array<string, array{name: string, native_name: string, short: string, rtl: bool}>
     */
    public static function all(): array
    {
        $supported = (array) config('localization.supported', []);
        $out = [];

        foreach (self::order($supported) as $locale) {
            $entry = $supported[$locale] ?? null;

            if ($entry === null) {
                continue;
            }

            $out[$locale] = [
                'name' => (string) ($entry['name'] ?? $locale),
                'native_name' => (string) ($entry['native_name'] ?? $entry['name'] ?? $locale),
                'short' => (string) ($entry['short'] ?? strtoupper(substr($locale, 0, 2))),
                'rtl' => (bool) ($entry['rtl'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Picker order, with anything the order list forgot appended rather than
     * dropped - a language missing from `picker_order` is a typo, and silently
     * hiding it would be a worse failure than listing it last.
     *
     * @param  array<string, mixed>  $supported
     * @return list<string>
     */
    private static function order(array $supported): array
    {
        $known = array_keys($supported);
        $ordered = array_values(array_intersect((array) config('localization.picker_order', []), $known));

        return array_values(array_unique([...$ordered, ...$known]));
    }

    /**
     * Display names in picker order. What the picker renders.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_column(self::all(), 'name'));
    }

    /**
     * Display name -> locale. The picker stages a name; the form posts this.
     *
     * @return array<string, string>
     */
    public static function localeMap(): array
    {
        $map = [];

        foreach (self::all() as $locale => $entry) {
            $map[$entry['name']] = $locale;
        }

        return $map;
    }

    /**
     * The display name for a locale, or null if it is not offered.
     */
    public static function nameFor(string $locale): ?string
    {
        return self::all()[$locale]['name'] ?? null;
    }

    /**
     * Is this locale offered? The picker, the controller and the middleware
     * all gate on the same answer.
     */
    public static function supports(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::all());
    }

    /**
     * Is this display name one a visitor may actually choose?
     *
     * Used to retire a stored preference from before the list changed: a name
     * that is no longer offered has to fall back rather than persist, or the
     * visitor sits in a language the picker cannot un-pick.
     */
    public static function offers(?string $name): bool
    {
        return $name !== null && in_array($name, self::names(), true);
    }

    /**
     * The default locale's display name - what an unrecognised browser tag or
     * a retired preference falls back to.
     */
    public static function defaultName(): string
    {
        $default = (string) config('localization.default', 'en');

        return self::nameFor($default) ?? (self::names()[0] ?? 'English');
    }

    /**
     * Does this locale read right to left?
     *
     * Declared in config rather than derived from a hardcoded list of scripts,
     * so adding Hebrew or Urdu later is a config line and not a code change.
     */
    public static function isRtl(?string $locale = null): bool
    {
        $locale ??= app()->getLocale();

        return (bool) (self::all()[$locale]['rtl'] ?? false);
    }

    /**
     * The value for the <html dir> attribute.
     *
     * Always written explicitly, never left to the browser's default: an
     * Arabic page inside a document still declaring ltr renders punctuation
     * and mixed Latin runs (currency codes, brand names) in the wrong order.
     */
    public static function direction(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }
}
