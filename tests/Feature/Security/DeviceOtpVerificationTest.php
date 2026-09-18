<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Events\OtpRequested;
use App\Http\Controllers\Security\DeviceVerificationController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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

    /**
     * The page is six single-digit boxes assembled into the one `otp` field the
     * server has always read. Only the first box offers the code from the mail.
     */
    public function test_the_page_is_six_digit_boxes_feeding_one_hidden_code_field(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        $this->assertSame(6, preg_match_all('/<input[^>]*inputmode="numeric"[^>]*maxlength="1"/', $html));
        $this->assertSame(1, substr_count($html, 'autocomplete="one-time-code"'));
        $this->assertStringContainsString('<input type="hidden" name="otp" :value="code">', $html);

        // The old single wide field is gone.
        $this->assertStringNotContainsString('placeholder="------"', $html);
        $this->assertStringNotContainsString('tracking-[0.5em]', $html);
    }

    /**
     * The "expires in" clock starts from the lifetime the server stamped when it
     * issued the code, and the address it went to is masked past two characters.
     */
    public function test_the_page_shows_the_real_remaining_lifetime_and_a_masked_address(): void
    {
        $user = User::factory()->create(['email' => 'roddy@example.com']);
        $this->pending($user);
        Cache::put('device_otp_expires_'.$user->id, time() + 300, now()->addMinutes(5));

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        // A few seconds may pass between the stamp and the render.
        $this->assertMatchesRegularExpression('/expiresIn: (29[0-9]|300),/', $html);
        $this->assertStringContainsString('ro***@example.com', $html);
        $this->assertStringNotContainsString('roddy@example.com', $html);
    }

    /** A code with no stamp (issued before the stamp existed) shows no clock rather than a made-up one. */
    public function test_without_a_stamp_the_clock_is_absent(): void
    {
        $user = User::factory()->create();
        $this->pending($user);

        $html = $this->get(route('security.verify-device'))->assertOk()->getContent();

        $this->assertStringContainsString('expiresIn: null,', $html);
    }

    /** Resending stamps the new code's lifetime beside it, at the one TTL the controller declares. */
    public function test_a_resent_code_is_stamped_with_its_lifetime(): void
    {
        Event::fake([OtpRequested::class]);

        $user = User::factory()->create();
        $this->pending($user);

        $this->from(route('security.verify-device'))
            ->post(route('security.verify-device.send'))
            ->assertRedirect(route('security.verify-device'));

        $stamp = Cache::get('device_otp_expires_'.$user->id);
        $ttl = DeviceVerificationController::OTP_TTL_MINUTES * 60;

        $this->assertIsInt($stamp);
        $this->assertEqualsWithDelta(time() + $ttl, $stamp, 5);
    }

    /** Burning or using a code takes its stamp with it, so a stale clock never shows for a dead code. */
    public function test_a_used_code_takes_its_stamp_with_it(): void
    {
        $user = User::factory()->create();
        $this->pending($user);
        Cache::put('device_otp_expires_'.$user->id, time() + 300, now()->addMinutes(5));

        $this->post(route('security.verify-device.verify'), ['otp' => '123456'])->assertRedirect(route('dashboard'));

        $this->assertNull(Cache::get('device_otp_expires_'.$user->id));
    }
}
