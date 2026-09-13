<?php

namespace Tests\Feature\Auth;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The auth modal's register action was the one auth action with no rate
 * limit (login had one all along), so a script could hammer sign-ups. Five
 * attempts per IP per ten minutes, counted before validation so junk
 * submissions spend the budget too.
 */
class RegistrationThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SiteSetting::put('features.signup_enabled', 'on', 'features');
        Cache::flush();
    }

    public function test_the_sixth_registration_attempt_from_one_ip_is_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $component = Volt::test('auth.auth-modal')->set('regEmail', 'not-an-email')->call('register');

            $component->assertHasErrors(['regEmail']);
            $this->assertStringNotContainsString('Too many', $component->errors()->first('regEmail'), "attempt {$attempt} must fail on validation, not the throttle");
        }

        $component = Volt::test('auth.auth-modal')->set('regEmail', 'not-an-email')->call('register');

        $component->assertHasErrors(['regEmail']);
        $this->assertStringContainsString('Too many', $component->errors()->first('regEmail'));
    }
}
