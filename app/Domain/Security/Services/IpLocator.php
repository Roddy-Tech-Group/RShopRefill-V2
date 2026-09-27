<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\DTOs\ResolvedLocation;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stevebauman\Location\Facades\Location;
use Throwable;

/**
 * The one place an IP address becomes a country.
 *
 * Everything that wants to know where a request came from goes through here
 * rather than calling the location package directly, because three rules have
 * to hold everywhere and each of them was previously nobody's job:
 *
 *  1. A lookup NEVER throws and never blocks a login. A location is a nice to
 *     have; signing in is not. Any failure resolves to null.
 *  2. An address that cannot have a public location (loopback, private range,
 *     reserved, malformed) is answered without a lookup at all. Asking a GeoIP
 *     database about 192.168.1.4 wastes work and, with the package's "testing"
 *     mode on, invents a United States answer for it.
 *  3. "I do not know" is a first-class answer, returned as null, never papered
 *     over with a default country. Every caller treats null as "score nothing",
 *     so a resolver outage makes the platform quieter, never wrong.
 *
 * Results are cached per address because an IP's country does not meaningfully
 * change within a day, and one customer's session can look itself up several
 * times across a login.
 */
class IpLocator
{
    /** How long a resolved address stays cached. */
    private const CACHE_HOURS = 24;

    /** Cache marker for "this address resolved to nothing", so misses are cached too. */
    private const UNKNOWN = 'unknown';

    public function __construct(private OperationalAlerter $alerter) {}

    /**
     * Resolve an address, or null when it has no determinable country.
     */
    public function locate(?string $ip): ?ResolvedLocation
    {
        $ip = trim((string) $ip);

        if ($ip === '' || ! $this->isPubliclyRoutable($ip)) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($ip));

        if ($cached === self::UNKNOWN) {
            return null;
        }

        if (is_array($cached) && isset($cached['country_code'])) {
            return ResolvedLocation::fromArray($cached);
        }

        $resolved = $this->lookup($ip);

        Cache::put(
            $this->cacheKey($ip),
            $resolved?->toArray() ?? self::UNKNOWN,
            now()->addHours(self::CACHE_HOURS),
        );

        return $resolved;
    }

    /**
     * Whether the GeoIP database is present and readable. Surfaced so a
     * pre-flight command can report on it without performing a lookup.
     */
    public function databaseAvailable(): bool
    {
        $path = (string) config('location.maxmind.local.path');

        return $path !== '' && is_readable($path);
    }

    /**
     * The database file's age, or null when it is absent. A stale file still
     * resolves correctly for the overwhelming majority of addresses; it drifts
     * slowly as ranges are reassigned, so this is a warning, never a failure.
     */
    public function databaseUpdatedAt(): ?Carbon
    {
        if (! $this->databaseAvailable()) {
            return null;
        }

        $timestamp = @filemtime((string) config('location.maxmind.local.path'));

        return $timestamp === false ? null : Carbon::createFromTimestamp($timestamp);
    }

    /**
     * Ask the driver, and translate every failure mode into null.
     */
    private function lookup(string $ip): ?ResolvedLocation
    {
        if (! $this->databaseAvailable()) {
            $this->alertMissingDatabase();

            return null;
        }

        try {
            $position = Location::get($ip);
        } catch (Throwable $e) {
            // A corrupt database, an address the reader rejects, a permissions
            // change. None of it is worth failing a login over.
            Log::warning('geoip.lookup_failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $position) {
            return null;
        }

        $code = strtoupper(trim((string) ($position->countryCode ?? '')));

        // A position with no country is useless to every caller, so it is a
        // miss rather than a partially populated answer.
        if (strlen($code) !== 2) {
            return null;
        }

        return new ResolvedLocation(
            countryCode: $code,
            countryName: $this->cleaned($position->countryName),
            city: $this->cleaned($position->cityName),
            timezone: $this->cleaned($position->timezone),
        );
    }

    /**
     * Whether an address could have a public location at all.
     *
     * FILTER_FLAG_NO_PRIV_RANGE and NO_RES_RANGE reject loopback, the private
     * IPv4 blocks, link-local, and the IPv6 equivalents, so the caller neither
     * wastes a lookup nor receives the package's fabricated testing answer.
     */
    private function isPubliclyRoutable(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function cleaned(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The database is how we know where anything comes from. Its absence is
     * not visible to a customer and would otherwise be invisible to us: every
     * lookup would simply return null and every geo rule would go quiet.
     */
    private function alertMissingDatabase(): void
    {
        $this->alerter->send(
            AlertSeverity::Sev2,
            'geoip:database-missing',
            'GeoIP database file is missing',
            'Location lookups are resolving to unknown, so login country checks cannot run and new devices will record no country. Run `php artisan location:update` on the server.',
            [
                'expected_path' => (string) config('location.maxmind.local.path'),
                'licence_key_configured' => filled(config('location.maxmind.license_key')),
            ],
            'Run `php artisan location:update`. It needs MAXMIND_LICENSE_KEY set to a free GeoLite2 key from maxmind.com.',
        );
    }

    private function cacheKey(string $ip): string
    {
        return 'geoip:'.sha1($ip);
    }
}
