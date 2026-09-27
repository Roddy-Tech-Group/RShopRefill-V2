<?php

namespace Tests\Feature\Security;

use App\Models\GeoVelocityEvent;
use App\Models\SiteSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * The two commands that make the country check readable and repairable:
 * `geo:report` (who got asked for a code, and is the data underneath healthy)
 * and `devices:backfill-countries` (give the 367 devices resolved before this
 * shipped an ISO code, so the money-path signal can see them).
 */
class GeoToolingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function pretendDatabaseExists(): void
    {
        config(['location.maxmind.local.path' => base_path('composer.json')]);
    }

    private function device(User $user, string $suffix, ?string $ip, ?string $code = null, ?string $country = null): TrustedDevice
    {
        return TrustedDevice::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'device-'.$suffix,
            'fingerprint' => hash('sha256', $suffix),
            'ip_address' => $ip,
            'country' => $country,
            'country_code' => $code,
            'last_seen_at' => now(),
        ]);
    }

    private function hop(User $user, string $from, string $to, bool $challenged, int $minutes): GeoVelocityEvent
    {
        return GeoVelocityEvent::query()->create([
            'user_id' => $user->id,
            'from_country_code' => $from,
            'to_country_code' => $to,
            'minutes_apart' => $minutes,
            'window_hours' => 6,
            'challenged' => $challenged,
            'destination_proven' => ! $challenged,
        ]);
    }

    public function test_the_report_is_quiet_when_nothing_has_moved(): void
    {
        $this->pretendDatabaseExists();

        Artisan::call('geo:report');

        $this->assertStringContainsString('No country change was seen', Artisan::output());
    }

    public function test_the_report_names_who_was_challenged_and_the_hops(): void
    {
        $this->pretendDatabaseExists();

        $vpnUser = User::factory()->create();
        $traveller = User::factory()->create();

        $this->hop($vpnUser, 'CM', 'US', challenged: true, minutes: 12);
        $this->hop($vpnUser, 'CM', 'US', challenged: false, minutes: 200);
        $this->hop($traveller, 'NG', 'GB', challenged: true, minutes: 90);

        Artisan::call('geo:report', ['--days' => 7]);
        $output = Artisan::output();

        $this->assertStringContainsString('3 country change(s) across 2 customer(s)', $output);
        $this->assertStringContainsString('2 asked for a code, 1 already proved', $output);
        $this->assertStringContainsString('CM -> US', $output);
        $this->assertStringContainsString('NG -> GB', $output);
        $this->assertStringContainsString($vpnUser->email, $output);
        $this->assertStringContainsString($traveller->email, $output);
    }

    /**
     * A rule reading a missing database is a rule that silently never fires,
     * so the report has to lead with that rather than print a reassuring zero.
     */
    public function test_the_report_shouts_when_the_geoip_database_is_missing(): void
    {
        config(['location.maxmind.local.path' => base_path('database/maxmind/nope.mmdb')]);

        Artisan::call('geo:report');
        $output = Artisan::output();

        $this->assertStringContainsString('MISSING', $output);
        $this->assertStringContainsString('location:update', $output);
    }

    public function test_the_report_flags_devices_with_no_country_code(): void
    {
        $this->pretendDatabaseExists();

        $user = User::factory()->create();
        $this->device($user, 'a', '102.90.100.141', code: 'CM');
        $this->device($user, 'b', '8.8.8.8', code: null, country: 'United States');

        Artisan::call('geo:report');
        $output = Artisan::output();

        $this->assertStringContainsString('1 of 2 carry a country code', $output);
        $this->assertStringContainsString('devices:backfill-countries', $output);
    }

    public function test_the_report_says_when_the_check_is_switched_off(): void
    {
        $this->pretendDatabaseExists();
        SiteSetting::put('features.login_country_check_enabled', 'off', 'features');
        Cache::flush();

        Artisan::call('geo:report');

        $this->assertStringContainsString('is OFF', Artisan::output());
    }

    public function test_the_report_speaks_json(): void
    {
        $this->pretendDatabaseExists();

        $user = User::factory()->create();
        $this->hop($user, 'CM', 'US', challenged: true, minutes: 12);

        Artisan::call('geo:report', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['enabled']);
        $this->assertTrue($report['database_available']);
        $this->assertSame(6, $report['window_hours']);
        $this->assertSame(90, $report['proof_days']);
        $this->assertSame(1, $report['events']);
        $this->assertSame(1, $report['challenged']);
        $this->assertSame(0, $report['already_proven']);
    }

    public function test_the_backfill_resolves_a_code_from_the_stored_address(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->andReturn(
            Position::make(['countryCode' => 'CM', 'countryName' => 'Cameroon', 'cityName' => 'Douala'])
        );

        $user = User::factory()->create();
        $device = $this->device($user, 'a', '102.90.100.141', code: null, country: 'Cameroon');

        Artisan::call('devices:backfill-countries');

        $this->assertSame('CM', $device->fresh()->country_code);
        $this->assertStringContainsString('Resolved:   1', Artisan::output());
    }

    /**
     * The whole reason the code column exists: the new source spells the same
     * place differently to the old one, which a name comparison would read as
     * the customer moving country.
     */
    public function test_the_backfill_reports_a_country_the_new_source_spells_differently(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->andReturn(
            Position::make(['countryCode' => 'NL', 'countryName' => 'Netherlands'])
        );

        $user = User::factory()->create();
        $device = $this->device($user, 'a', '145.100.1.1', code: null, country: 'The Netherlands');

        Artisan::call('devices:backfill-countries');

        $this->assertStringContainsString('Renamed:    1', Artisan::output());
        $this->assertSame('NL', $device->fresh()->country_code);
        $this->assertSame('Netherlands', $device->fresh()->country);
    }

    public function test_the_backfill_leaves_already_resolved_devices_alone(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->never();

        $user = User::factory()->create();
        $this->device($user, 'a', '102.90.100.141', code: 'CM', country: 'Cameroon');

        Artisan::call('devices:backfill-countries');

        $this->assertStringContainsString('already has a country code', Artisan::output());
    }

    public function test_the_backfill_writes_nothing_on_a_dry_run(): void
    {
        $this->pretendDatabaseExists();
        Location::shouldReceive('get')->andReturn(
            Position::make(['countryCode' => 'CM', 'countryName' => 'Cameroon'])
        );

        $user = User::factory()->create();
        $device = $this->device($user, 'a', '102.90.100.141');

        Artisan::call('devices:backfill-countries', ['--dry-run' => true]);

        $this->assertNull($device->fresh()->country_code);
        $this->assertStringContainsString('nothing was written', Artisan::output());
    }

    public function test_the_backfill_refuses_without_a_database(): void
    {
        config(['location.maxmind.local.path' => base_path('database/maxmind/nope.mmdb')]);

        $this->assertSame(1, Artisan::call('devices:backfill-countries'));
        $this->assertStringContainsString('No GeoIP database on disk', Artisan::output());
    }
}
