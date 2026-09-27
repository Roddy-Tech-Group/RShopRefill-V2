<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Services\IpLocator;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * The one place an IP becomes a country.
 *
 * Production spent July to September resolving every login through
 * ip-api.com's free tier: non-commercial licence, plain HTTP, 45 requests a
 * minute, one blocking call on the authentication path. This covers the
 * replacement's contract, which is mostly about what it refuses to do:
 * never throw, never guess, never look up an address that cannot have a
 * public location, and never let a missing database pass unnoticed.
 */
class IpLocatorTest extends TestCase
{
    use RefreshDatabase;

    /** A real, public address; the driver is mocked, so the value only has to be routable. */
    private const PUBLIC_IP = '102.90.100.141';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * The locator refuses to look anything up unless the database is on disk,
     * so tests that want a lookup point the config at a file that exists.
     */
    private function pretendDatabaseExists(): void
    {
        config(['location.maxmind.local.path' => base_path('composer.json')]);
    }

    private function position(?string $code, ?string $name = null): Position
    {
        return Position::make([
            'countryCode' => $code,
            'countryName' => $name,
            'cityName' => 'Douala',
            'timezone' => 'Africa/Douala',
        ]);
    }

    public function test_it_resolves_a_public_address(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andReturn($this->position('CM', 'Cameroon'));

        $location = app(IpLocator::class)->locate(self::PUBLIC_IP);

        $this->assertNotNull($location);
        $this->assertSame('CM', $location->countryCode);
        $this->assertSame('Cameroon', $location->countryName);
        $this->assertSame('Douala', $location->city);
        $this->assertSame('Africa/Douala', $location->timezone);
    }

    public function test_a_country_code_is_normalised_to_upper_case(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andReturn($this->position('cm', 'Cameroon'));

        $this->assertSame('CM', app(IpLocator::class)->locate(self::PUBLIC_IP)?->countryCode);
    }

    /**
     * Private and loopback addresses cannot have a public location. Looking
     * them up wastes the call and, with the package's "testing" mode left on,
     * comes back as a confident United States, which a country comparison
     * would read as a real move.
     */
    public function test_addresses_that_cannot_have_a_location_are_never_looked_up(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->never();

        $locator = app(IpLocator::class);

        foreach (['127.0.0.1', '::1', '192.168.1.40', '10.0.0.8', '172.16.4.1', 'not-an-ip', '', null] as $ip) {
            $this->assertNull($locator->locate($ip), "{$ip} must resolve to unknown without a lookup");
        }
    }

    public function test_the_package_testing_substitution_is_off_by_default(): void
    {
        $this->assertFalse(
            config('location.testing.enabled'),
            'testing mode invents a United States answer for local addresses, which reads as a real country change',
        );
    }

    public function test_a_result_is_cached_so_a_login_does_not_re_resolve(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andReturn($this->position('CM', 'Cameroon'));

        $locator = app(IpLocator::class);

        $this->assertSame('CM', $locator->locate(self::PUBLIC_IP)?->countryCode);
        $this->assertSame('CM', $locator->locate(self::PUBLIC_IP)?->countryCode);
        $this->assertSame('CM', $locator->locate(self::PUBLIC_IP)?->countryCode);
    }

    /**
     * A miss is cached too. Without this, an address the database has no entry
     * for would be looked up again on every single login forever.
     */
    public function test_an_unresolved_address_is_cached_as_unknown(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andReturn(false);

        $locator = app(IpLocator::class);

        $this->assertNull($locator->locate(self::PUBLIC_IP));
        $this->assertNull($locator->locate(self::PUBLIC_IP));
    }

    /**
     * A position with no country is no use to any caller, so it is a miss
     * rather than an object with holes in it.
     */
    public function test_a_position_without_a_country_is_a_miss(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andReturn($this->position(null));

        $this->assertNull(app(IpLocator::class)->locate(self::PUBLIC_IP));
    }

    /**
     * A location is a nice to have. Signing in is not.
     */
    public function test_a_driver_failure_never_escapes(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->once()->andThrow(new RuntimeException('corrupt database'));

        $this->assertNull(app(IpLocator::class)->locate(self::PUBLIC_IP));
    }

    /**
     * A missing database would otherwise be perfectly silent: every lookup
     * returns null, every geo rule goes quiet, and no customer notices.
     */
    public function test_a_missing_database_resolves_to_unknown_and_raises_a_sev2(): void
    {
        config(['location.maxmind.local.path' => base_path('database/maxmind/does-not-exist.mmdb')]);
        Location::shouldReceive('get')->never();

        $alerter = $this->mock(OperationalAlerter::class);
        $alerter->shouldReceive('send')
            ->once()
            ->withArgs(fn (AlertSeverity $severity, string $key) => $severity === AlertSeverity::Sev2
                && $key === 'geoip:database-missing')
            ->andReturnTrue();

        $locator = app(IpLocator::class);

        $this->assertNull($locator->locate(self::PUBLIC_IP));
        $this->assertFalse($locator->databaseAvailable());
        $this->assertNull($locator->databaseUpdatedAt());
    }

    public function test_it_reports_the_database_age_when_the_file_is_there(): void
    {
        $this->pretendDatabaseExists();

        $locator = app(IpLocator::class);

        $this->assertTrue($locator->databaseAvailable());
        $this->assertNotNull($locator->databaseUpdatedAt());
    }
}
