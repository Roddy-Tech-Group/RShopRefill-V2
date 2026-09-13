<?php

namespace App\Support;

/**
 * The languages a visitor can actually reach, and how each one is produced.
 *
 * Two mechanisms sit behind one picker:
 *
 *   - SERVER languages (config localization.supported) are rendered by Laravel
 *     from a lang/{code} directory. Real translations, no third-party request,
 *     no flash of English before they apply.
 *
 *   - MACHINE languages (config localization.machine) are an overlay the
 *     browser translate widget paints over a server-rendered page.
 *
 * Everything that needs the list resolves through here so there is exactly one
 * of it. Before this existed the picker held its own array of display names and
 * the engine held its own map of 88, which is how a visitor could be auto-
 * detected into a language the picker could not show back to them.
 */
class Languages
{
    /**
     * Every offered language, in picker order, keyed by language key.
     *
     * Each entry carries `server` - true when Laravel renders it, false when
     * the widget does. That flag is the whole difference between a page that
     * costs a third-party script and one that does not.
     *
     * @return array<string, array{name: string, native_name: string, short: string, engine: ?string, server: bool}>
     */
    public static function all(): array
    {
        $server = (array) config('localization.supported', []);
        $machine = (array) config('localization.machine', []);

        $out = [];

        foreach (self::order($server, $machine) as $key) {
            $entry = $server[$key] ?? $machine[$key] ?? null;

            if ($entry === null) {
                continue;
            }

            $out[$key] = [
                'name' => (string) ($entry['name'] ?? $key),
                'native_name' => (string) ($entry['native_name'] ?? $entry['name'] ?? $key),
                'short' => (string) ($entry['short'] ?? strtoupper(substr($key, 0, 2))),
                // Server languages have no engine code: asking the widget to
                // translate a page Laravel already translated is the collision
                // this class exists to prevent.
                'engine' => isset($server[$key]) ? null : (string) ($entry['engine'] ?? $key),
                'server' => isset($server[$key]),
            ];
        }

        return $out;
    }

    /**
     * Picker order, with anything the order list forgot appended rather than
     * dropped - a language missing from `picker_order` is a typo, and silently
     * hiding it would be a worse failure than listing it last.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $machine
     * @return list<string>
     */
    private static function order(array $server, array $machine): array
    {
        $known = array_keys($server + $machine);
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
     * Display name -> widget language code, for machine languages only.
     *
     * Server languages are deliberately absent: the engine reads this map to
     * decide whether to translate at all, so an entry here means "the widget
     * owns this language".
     *
     * @return array<string, string>
     */
    public static function engineMap(): array
    {
        $map = [];

        foreach (self::all() as $entry) {
            if ($entry['engine'] !== null) {
                $map[$entry['name']] = $entry['engine'];
            }
        }

        return $map;
    }

    /**
     * Display name -> the locale the SERVER should render for it.
     *
     * A server language renders itself. A machine language renders in the
     * default locale, because the widget translates out of whatever Laravel
     * produced: pointing it at a French page while telling it the source is
     * English is how already-translated text gets machine-translated a second
     * time.
     *
     * @return array<string, string>
     */
    public static function serverLocaleMap(): array
    {
        $default = (string) config('localization.default', 'en');
        $map = [];

        foreach (self::all() as $key => $entry) {
            $map[$entry['name']] = $entry['server'] ? $key : $default;
        }

        return $map;
    }

    /**
     * The display name for a language key, or null if it is not offered.
     */
    public static function nameFor(string $key): ?string
    {
        return self::all()[$key]['name'] ?? null;
    }

    /**
     * Is this display name one a visitor may actually choose?
     *
     * Used to retire a stored preference from before the list was trimmed: a
     * name that is no longer offered has to fall back rather than persist,
     * or the visitor sits in a language the picker cannot un-pick.
     */
    public static function offers(?string $name): bool
    {
        return $name !== null && in_array($name, self::names(), true);
    }

    /**
     * The default language's display name - what an unrecognised browser tag
     * or a retired preference falls back to.
     */
    public static function defaultName(): string
    {
        $default = (string) config('localization.default', 'en');

        return self::nameFor($default) ?? (self::names()[0] ?? 'English');
    }

    /**
     * Browser language tag -> display name, for first-visit auto-detection.
     *
     * Built from the offered list rather than kept by hand, plus the aliases a
     * browser may report for a tag we do hold. Region subtags are collapsed by
     * the caller, so this holds primary tags and the handful of exceptions
     * where the region is the language (zh-TW is not Mandarin).
     *
     * @return array<string, string>
     */
    public static function detectionMap(): array
    {
        $map = [];

        foreach (self::all() as $key => $entry) {
            $map[$key] = $entry['name'];

            if ($entry['engine'] !== null) {
                $map[strtolower($entry['engine'])] = $entry['name'];
            }
        }

        // Tags a browser reports that do not match a key or an engine code.
        // Only aliases for languages actually offered - an alias pointing at a
        // language the picker does not list would recreate the trap.
        foreach ([
            'zh-cn' => 'zh', 'zh-hans' => 'zh', 'zh-sg' => 'zh',
            'pt-br' => 'pt', 'pt-pt' => 'pt',
            'ar-eg' => 'ar', 'ar-sa' => 'ar',
            'fr-ca' => 'fr', 'fr-be' => 'fr', 'fr-ch' => 'fr',
            'en-gb' => 'en', 'en-us' => 'en',
            'es-es' => 'es', 'es-mx' => 'es', 'es-419' => 'es',
            'de-at' => 'de', 'de-ch' => 'de',
        ] as $tag => $key) {
            if (isset($map[$key])) {
                $map[$tag] = $map[$key];
            }
        }

        return $map;
    }
}
