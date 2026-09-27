<?php

namespace App\Support;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Coverage countries for a multi-country (regional / global) eSIM.
 *
 * Suppliers disagree on where this lives, so both shapes are read here rather
 * than in each view that needs a count:
 *
 *   - Zendit puts ISO codes in `metadata.coverage`.
 *   - Airalo only lists country NAMES, inside the package's installation HTML.
 *
 * Every package of a product shares the same coverage, so the first variant
 * that carries any is enough - which matters, because eSIM variant metadata is
 * large and scanning all of them per card would be wasteful.
 *
 * Extracted from shop/esim.blade.php so the branded destination cards and the
 * detail page cannot drift on the same number. The detail page is the
 * reference implementation; this is a verbatim lift of it.
 */
class EsimCoverage
{
    /**
     * Country names covered by these variants, deduped and alphabetical.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return Collection<int, string>
     */
    public static function list(Collection $variants): Collection
    {
        $isoToName = array_flip(config('countries.codes', []));
        $names = collect();

        foreach ($variants as $variant) {
            $meta = $variant->metadata ?? [];

            foreach ((array) ($meta['coverage'] ?? []) as $code) {
                $code = strtoupper((string) $code);
                $names->push($isoToName[$code] ?? $code);
            }

            // Airalo carries no `coverage` key. What it does carry is one
            // coverage_networks row per covered country, which is the list the
            // detail page's Countries & Networks modal renders - so counting
            // those rows is counting the same countries the customer can open
            // and read. Do NOT use metadata.countries here: on a regional
            // product that is the single supplier region code (["WW"]), not a
            // country list, and it would report every region as covering 1.
            if ($names->isEmpty()) {
                foreach ((array) ($meta['coverage_networks'] ?? []) as $row) {
                    $code = strtoupper(trim((string) ($row['country'] ?? '')));
                    if ($code !== '') {
                        $names->push($isoToName[$code] ?? $code);
                    }
                }
            }

            if ($names->isEmpty()) {
                $html = $meta['raw_payload']['qr_installation']
                    ?? ($meta['raw_payload']['manual_installation'] ?? '');

                if ($html && preg_match('~Coverage:\s*</b>\s*(.*?)</p>~is', $html, $matches)) {
                    foreach (preg_split('~,|\sand\s~i', strip_tags($matches[1])) as $name) {
                        $name = trim($name, " .\t\n\r\0\x0B");
                        if ($name !== '') {
                            $names->push($name);
                        }
                    }
                }
            }

            if ($names->isNotEmpty()) {
                break;
            }
        }

        return $names->unique()->sort()->values();
    }

    /**
     * ISO codes of the covered countries, for anything that needs flags rather
     * than names. Only the sources that actually carry codes are read - the
     * Airalo HTML fallback in list() gives names with no reliable code, so a
     * product that only has that returns an empty set here rather than a set
     * of guesses.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, string>
     */
    public static function codes(Collection $variants): array
    {
        foreach ($variants as $variant) {
            $meta = $variant->metadata ?? [];

            $codes = collect((array) ($meta['coverage'] ?? []))
                ->concat(collect((array) ($meta['coverage_networks'] ?? []))->pluck('country'))
                ->map(fn ($c) => strtoupper(trim((string) $c)))
                ->filter(fn ($c) => strlen($c) === 2)
                ->unique()
                ->values();

            if ($codes->isNotEmpty()) {
                return $codes->all();
            }
        }

        return [];
    }

    /**
     * Covered ISO codes ordered by how well the plan actually serves each one:
     * most carrier networks first, then alphabetically so the order is stable
     * between runs and between cache rebuilds.
     *
     * This is the honest answer to "which countries should represent this
     * region" - it is the supplier's own coverage depth, not an opinion about
     * which countries matter. Falls back to plain coverage order for products
     * that list countries without per-country networks.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, string>
     */
    public static function ranked(Collection $variants): array
    {
        foreach ($variants as $variant) {
            $rows = (array) (($variant->metadata ?? [])['coverage_networks'] ?? []);

            if ($rows === []) {
                continue;
            }

            $byCountry = [];
            foreach ($rows as $row) {
                $code = strtoupper(trim((string) ($row['country'] ?? '')));
                if (strlen($code) !== 2) {
                    continue;
                }
                $byCountry[$code] = max(
                    $byCountry[$code] ?? 0,
                    count(array_filter((array) ($row['networks'] ?? []), fn ($n) => is_array($n) && ! empty($n['name'])))
                );
            }

            if ($byCountry !== []) {
                // Depth desc, then code asc. ksort first so the tiebreak is
                // alphabetical rather than whatever order the supplier sent.
                ksort($byCountry);
                arsort($byCountry, SORT_NUMERIC);

                return array_keys($byCountry);
            }
        }

        return self::codes($variants);
    }

    /**
     * How many countries these variants cover. 0 means "the supplier told us
     * nothing", which callers must treat as unknown rather than as none - a
     * card showing "Stay connected across 0 countries." would be worse than a
     * card showing no count line at all.
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    public static function count(Collection $variants): int
    {
        return self::list($variants)->count();
    }
}
