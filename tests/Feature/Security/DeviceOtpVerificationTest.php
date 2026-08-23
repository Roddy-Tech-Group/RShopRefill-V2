<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The device email-OTP verify step. The rule pinned here: a six-digit code
 * cannot be brute-forced inside its lifetime. Wrong guesses are capped and the
 * code is burned after the fifth miss, forcing the customer to request a new
 * one. Comparison is constant-time (hash_equals).
 */
class DeviceOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(User $user): void
    {
        Cache::put('device_otp_'.$user->id, '123456', now()->addMinutes(10));

        $this->actingAs($user)->withSession([
            'pending_email_otp' => true,
            'pending_device_id' => 999999, // no such device; the verify path tolerates it
        ]);
    }

    public function test_correct_code_verifies_the_device(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $response = $this->post(route('security.verify-device.verify'), ['otp' => '123456']);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionMissing('pending_email_otp');
        $this->assertNull(Cache::get('device_otp_'.$user->id));
    }

    public function test_code_is_burned_after_five_wrong_guesses(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        // Four misses: rejected, but the code survives.
        for ($i = 0; $i < 4; $i++) {
            $this->from(route('security.verify-device'))
                ->post(route('security.verify-device.verify'), ['otp' => '000000'])
                ->assertSessionHasErrors('otp');
        }
        $this->assertNotNull(Cache::get('device_otp_'.$user->id), 'Code should survive the first four misses.');

        // Fifth miss burns the code.
        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.verify'), ['otp' => '000000'])
            ->assertSessionHasErrors('otp');
        $this->assertNull(Cache::get('device_otp_'.$user->id), 'Code should be burned after the fifth miss.');

        // Even the correct code no longer works: it must be re-requested.
        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.verify'), ['otp' => '123456'])
            ->assertSessionHasErrors('otp');
    }
}
