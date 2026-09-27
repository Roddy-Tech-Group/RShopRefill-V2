<?php

namespace Tests\Feature\Security;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Services\LoginCountryGuard;
use App\Models\GeoVelocityEvent;
use App\Models\SiteSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserLoginCountry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

/**
 * Impossible travel, done by country and time.
 *
 * The rule this replaces was "the IP differs from the one cached an hour ago",
 * which knew nothing about places. It could not tell a customer walking from
 * WiFi onto mobile data from a stolen password on another continent, and
 * because its cache lived an hour it barely fired at all: 12 sessions across
 * one customer in two and a half months of production.
 *
 * The contract proved here:
 *  - same country never fires, whatever the IP does;
 *  - an unknown location never fires, and is never recorded as a place;
 *  - two countries inside the window ask for the emailed code;
 *  - a country the customer has already proved does not ask again, which is
 *    what keeps the rule usable given that a fifth of our devices resolve to
 *    the United States on VPN exits;
 *  - proof expires;
 *  - it can only ever ask for a code, and a failure to record never costs
 *    anyone their login.
 */
class LoginCountryCheckTest extends TestCase
{
    use RefreshDatabase;

    private const CAMEROON = '102.90.100.141';

    private const UNITED_STATES = '8.8.8.8';

    private const NIGERIA = '41.58.12.9';

    /** @var array<string, array{0: string, 1: string}> */
    private const PLACES = [
        self::CAMEROON => ['CM', 'Cameroon'],
        self::UNITED_STATES => ['US', 'United States'],
        self::NIGERIA => ['NG', 'Nigeria'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['location.maxmind.local.path' => base_path('composer.json')]);

        Location::shouldReceive('get')->andReturnUsing(function (string $ip) {
            if (! isset(self::PLACES[$ip])) {
                return false;
            }

            [$code, $name] = self::PLACES[$ip];

            return Position::make(['countryCode' => $code, 'countryName' => $name]);
        });
    }

    private function guard(): LoginCountryGuard
    {
        return app(LoginCountryGuard::class);
    }

    /**
     * Put the account in a country at a point in time, the way a previous
     * login would have.
     */
    private function seenIn(User $user, string $code, string $minutesAgo, bool $proven = true): UserLoginCountry
    {
        return UserLoginCountry::query()->create([
            'user_id' => $user->id,
            'country_code' => $code,
            'country_name' => $code,
            'last_seen_at' => now()->subMinutes((int) $minutesAgo),
            'proven_at' => $proven ? now()->subMinutes((int) $minutesAgo) : null,
        ]);
    }

    public function test_the_same_country_never_asks_for_a_code(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertFalse($this->guard()->challengeRequired($user, self::CAMEROON));
        $this->assertSame(0, GeoVelocityEvent::count(), 'staying put is not an event');
    }

    /**
     * The failure the old rule was built on: a phone dropping from WiFi to
     * mobile data changes the IP and nothing else.
     */
    public function test_a_changed_ip_inside_one_country_never_asks_for_a_code(): void
    {
        $user = User::factory()->create();

        // Two different Cameroonian addresses, minutes apart.
        $this->assertFalse($this->guard()->challengeRequired($user, self::CAMEROON));
        $this->assertFalse($this->guard()->challengeRequired($user, self::CAMEROON));
        $this->assertSame(0, GeoVelocityEvent::count());
    }

    public function test_a_first_login_ever_is_recorded_and_never_challenged(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->guard()->challengeRequired($user, self::CAMEROON));

        $row = UserLoginCountry::sole();
        $this->assertSame('CM', $row->country_code);
        $this->assertSame('Cameroon', $row->country_name);
        $this->assertSame(self::CAMEROON, $row->last_ip);
        $this->assertNull($row->proven_at, 'a country is not proved merely by appearing');
    }

    public function test_two_countries_inside_the_window_ask_for_a_code(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '45');

        $this->assertTrue($this->guard()->challengeRequired($user, self::UNITED_STATES, 'device-abc'));

        $event = GeoVelocityEvent::sole();
        $this->assertSame('CM', $event->from_country_code);
        $this->assertSame('US', $event->to_country_code);
        $this->assertTrue($event->challenged);
        $this->assertFalse($event->destination_proven);
        $this->assertSame(45, $event->minutes_apart);
        $this->assertSame(6, $event->window_hours);
        $this->assertSame('device-abc', $event->device_uuid);
    }

    /**
     * Two countries far enough apart in time is a flight, not a theft.
     */
    public function test_two_countries_outside_the_window_are_left_alone(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', (string) (60 * 9));

        $this->assertFalse($this->guard()->challengeRequired($user, self::UNITED_STATES));
        $this->assertSame(0, GeoVelocityEvent::count());
    }

    /**
     * The VPN case, which is most of what we actually see: the customer has
     * already answered a code from this country once, so we stop asking.
     */
    public function test_a_country_the_customer_has_proved_does_not_ask_again(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');
        $this->seenIn($user, 'US', (string) (60 * 24 * 3), proven: true);

        $this->assertFalse($this->guard()->challengeRequired($user, self::UNITED_STATES));

        // Still recorded, so the report can show it was seen and why it passed.
        $event = GeoVelocityEvent::sole();
        $this->assertFalse($event->challenged);
        $this->assertTrue($event->destination_proven);
    }

    public function test_proof_expires_and_the_country_is_asked_about_again(): void
    {
        config(['risk.thresholds.login_country_proof_days' => 90]);

        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $stale = $this->seenIn($user, 'US', (string) (60 * 24 * 200));
        $stale->forceFill(['proven_at' => now()->subDays(200)])->save();

        $this->assertTrue($this->guard()->challengeRequired($user, self::UNITED_STATES));
        $this->assertTrue(GeoVelocityEvent::sole()->challenged);
    }

    /**
     * We never guess. A fabricated country reads as a real move, so an
     * address we cannot place scores nothing and is not written down as a
     * place the customer has been.
     */
    public function test_an_unknown_location_is_never_scored_and_never_recorded(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertFalse($this->guard()->challengeRequired($user, '203.0.113.7'));
        $this->assertSame(0, GeoVelocityEvent::count());
        $this->assertSame(1, UserLoginCountry::count(), 'no phantom country row');
    }

    public function test_a_private_address_is_never_scored(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertFalse($this->guard()->challengeRequired($user, '192.168.1.22'));
        $this->assertSame(0, GeoVelocityEvent::count());
    }

    public function test_the_off_switch_stops_the_check_entirely(): void
    {
        SiteSetting::put('features.login_country_check_enabled', 'off', 'features');
        Cache::flush();

        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertFalse($this->guard()->challengeRequired($user, self::UNITED_STATES));
        $this->assertSame(0, GeoVelocityEvent::count());
    }

    public function test_the_hop_is_recorded_with_the_most_recent_country_not_the_oldest(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '300');
        $this->seenIn($user, 'NG', '20');

        $this->assertTrue($this->guard()->challengeRequired($user, self::UNITED_STATES));
        $this->assertSame('NG', GeoVelocityEvent::sole()->from_country_code);
    }

    public function test_the_arriving_country_is_stamped_as_just_seen(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '45');

        $this->guard()->challengeRequired($user, self::UNITED_STATES);

        $arrived = UserLoginCountry::where('country_code', 'US')->sole();
        $this->assertTrue($arrived->last_seen_at->greaterThan(now()->subMinute()));
    }

    /**
     * The audit row is a record of a decision, never the decision itself.
     */
    public function test_a_failure_to_record_never_costs_the_customer_their_login(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        Schema::drop('geo_velocity_events');

        $this->assertTrue(
            $this->guard()->challengeRequired($user, self::UNITED_STATES),
            'the decision must still stand when the log cannot be written',
        );
    }

    public function test_marking_a_country_proven_stops_the_next_challenge(): void
    {
        $user = User::factory()->create();

        // Arrives in the US, gets asked.
        $this->seenIn($user, 'CM', '30');
        $this->assertTrue($this->guard()->challengeRequired($user, self::UNITED_STATES));

        // Answers the code.
        $this->guard()->markProven($user, self::UNITED_STATES);
        $this->assertNotNull(UserLoginCountry::where('country_code', 'US')->sole()->proven_at);

        // Comes back the same way and is left alone.
        $this->seenIn($user, 'NG', '5');
        $this->assertFalse($this->guard()->challengeRequired($user, self::UNITED_STATES));
    }

    public function test_marking_an_unknown_location_proven_does_nothing(): void
    {
        $user = User::factory()->create();
        $this->guard()->markProven($user, '192.168.1.5');

        $this->assertSame(0, UserLoginCountry::count());
    }

    /**
     * The login path's contract: HIGH is what forces a code, including for a
     * customer whose preference is "never". 50 on its own reaches it, which
     * the old rule's 40 deliberately did not.
     */
    /**
     * A device the account has used for months, so nothing but the country
     * change can be contributing to the score.
     */
    private function settledDevice(User $user): TrustedDevice
    {
        return TrustedDevice::query()->create([
            'user_id' => $user->id,
            'device_uuid' => 'device-'.$user->id,
            'fingerprint' => hash('sha256', 'device-'.$user->id),
            'trusted_since' => now()->subMonths(2),
            'last_seen_at' => now()->subHours(2),
        ]);
    }

    public function test_a_country_change_reaches_high_on_a_fully_trusted_device(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertSame(
            'HIGH',
            app(RiskEngine::class)->evaluateLoginRisk($user, $this->settledDevice($user), self::UNITED_STATES),
            'HIGH is what forces the code for a customer whose preference is never',
        );
    }

    public function test_a_trusted_device_in_its_usual_country_stays_low(): void
    {
        $user = User::factory()->create();
        $this->seenIn($user, 'CM', '30');

        $this->assertSame(
            'LOW',
            app(RiskEngine::class)->evaluateLoginRisk($user, $this->settledDevice($user), self::CAMEROON),
        );
    }
}
