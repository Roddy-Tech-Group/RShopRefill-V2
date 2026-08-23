<?php

namespace Tests\Feature\Pagination;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Customer-facing lists that were reading a whole table per render.
 *
 * The security centre listed every trusted_devices row an account had ever
 * collected, and re-saved each of them from inside the render path. It must
 * show only the most recently seen devices. (The trade-chat bounds this file
 * also covered died with gift card trading.)
 */
class CustomerListBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_security_centre_lists_only_the_most_recent_trusted_devices(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 15) as $i) {
            TrustedDevice::create([
                'user_id' => $user->id,
                'device_uuid' => 'uuid-'.$i,
                'fingerprint' => 'fingerprint-'.$i,
                'browser' => 'Chrome',
                'os' => 'Windows',
                'device_type' => 'desktop',
                'ip_address' => '198.51.100.7',
                'city' => sprintf('CityAAA%02d', $i),
                'user_agent' => 'Mozilla/5.0',
                'trusted_since' => now()->subDays(30),
                'last_seen_at' => now()->subMinutes($i),
            ]);
        }

        $this->actingAs($user);

        Volt::test('settings.security')
            ->assertSee('CityAAA01')
            ->assertSee('CityAAA10')
            ->assertDontSee('CityAAA11')
            ->assertDontSee('CityAAA15')
            ->assertSee('Showing your 10 most recently used devices.');
    }
}
