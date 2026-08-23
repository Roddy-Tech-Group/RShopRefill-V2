<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Services\DeviceTrustManager;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Jenssegers\Agent\Agent;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * Device rows used to store a null device_name, so both the admin customer
 * page and the customer security centre showed "Unknown Device" above a
 * perfectly good "iOS / Safari" line. Names are now derived from the user
 * agent at capture time and backfilled for old rows.
 *
 * Honest ceiling: user agents identify the device FAMILY, not the retail
 * model. Every iPhone reports just "iPhone" (Apple strips the model), Android
 * exposes its model string (SM-G991B, Pixel 7), and privacy-reduced Chrome
 * hides it entirely.
 */
class DeviceFriendlyNameTest extends TestCase
{
    use RefreshDatabase;

    private const UAS = [
        'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
        'samsung' => 'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
        'pixel' => 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'reduced_android' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
        'ipad' => 'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
    ];

    private function nameFor(string $ua): ?string
    {
        $agent = new Agent;
        $agent->setUserAgent($ua);

        return DeviceTrustManager::friendlyDeviceName($agent);
    }

    public function test_friendly_names_are_derived_per_device_family(): void
    {
        $this->assertSame('iPhone', $this->nameFor(self::UAS['iphone']));
        $this->assertSame('iPad', $this->nameFor(self::UAS['ipad']));
        $this->assertSame('SM-G991B (Android)', $this->nameFor(self::UAS['samsung']));
        $this->assertSame('Pixel 7 (Android)', $this->nameFor(self::UAS['pixel']));
        $this->assertSame('Android device', $this->nameFor(self::UAS['reduced_android']));
        $this->assertSame('Windows PC', $this->nameFor(self::UAS['windows']));
        $this->assertSame('Mac', $this->nameFor(self::UAS['mac']));
    }

    public function test_registering_a_device_persists_the_derived_name(): void
    {
        Location::shouldReceive('get')->andReturn(false);

        $user = User::factory()->create();

        $device = app(DeviceTrustManager::class)->registerOrUpdateDevice(
            $user,
            ['installation_id' => 'test-install-1'],
            '203.0.113.10',
            self::UAS['iphone'],
        );

        $this->assertSame('iPhone', $device->fresh()->device_name);
    }

    public function test_the_backfill_names_old_rows_without_touching_named_ones(): void
    {
        $user = User::factory()->create();

        $unnamed = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => 'legacy-1',
            'fingerprint' => hash('sha256', 'legacy-1'),
            'user_agent' => self::UAS['samsung'],
            'os' => 'AndroidOS',
            'browser' => 'Chrome',
            'device_type' => 'mobile',
        ]);

        $named = TrustedDevice::create([
            'user_id' => $user->id,
            'device_uuid' => 'legacy-2',
            'fingerprint' => hash('sha256', 'legacy-2'),
            'device_name' => 'My work laptop',
            'user_agent' => self::UAS['windows'],
            'os' => 'Windows',
            'browser' => 'Chrome',
            'device_type' => 'desktop',
        ]);

        $this->artisan('devices:backfill-agents')->assertSuccessful();

        $this->assertSame('SM-G991B (Android)', $unnamed->fresh()->device_name);
        $this->assertSame('My work laptop', $named->fresh()->device_name, 'an explicit name must never be overwritten');
    }
}
