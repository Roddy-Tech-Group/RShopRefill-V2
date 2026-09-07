<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Notifications\EmailOtpNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The Protected Mode recovery code lifts a fraud restriction, so it must not
 * be guessable: five wrong answers burn the code (the six-digit space is a
 * million possibilities, and an unthrottled Livewire call could walk it),
 * comparison is constant-time, and generation is cryptographic across the
 * full range.
 */
class RecoveryOtpGuessCapTest extends TestCase
{
    use RefreshDatabase;

    private function restrictedUser(): User
    {
        // A PIN keeps verifyOtp on the PIN step instead of completing the
        // recovery, so these tests can focus on the code itself.
        return User::factory()->create([
            'restricted_at' => now(),
            'transaction_pin' => Hash::make('4826'),
            'transaction_pin_set_at' => now(),
            'pin_status' => 'active',
        ]);
    }

    public function test_five_wrong_codes_burn_the_code(): void
    {
        $user = $this->restrictedUser();
        $this->actingAs($user);
        Cache::put('recovery_otp_'.$user->id, '123456', now()->addMinutes(10));

        $component = Volt::test('security.recover');

        foreach (range(1, 4) as $attempt) {
            $component->set('otp', '000000')->call('verifyOtp')->assertHasErrors(['otp']);
            $this->assertSame('123456', Cache::get('recovery_otp_'.$user->id), "code must survive wrong guess {$attempt}");
        }

        $component->set('otp', '000000')->call('verifyOtp')->assertHasErrors(['otp']);
        $this->assertStringContainsString('Too many incorrect codes', $component->errors()->first('otp'));
        $this->assertNull(Cache::get('recovery_otp_'.$user->id), 'the fifth wrong guess must burn the code');

        // Even the right code is useless now.
        $component->set('otp', '123456')->call('verifyOtp')->assertHasErrors(['otp']);
        $this->assertNotNull($user->fresh()->restricted_at);
    }

    public function test_the_right_code_still_moves_the_customer_on(): void
    {
        $user = $this->restrictedUser();
        $this->actingAs($user);
        Cache::put('recovery_otp_'.$user->id, '654321', now()->addMinutes(10));

        Volt::test('security.recover')
            ->set('otp', '654321')
            ->call('verifyOtp')
            ->assertHasNoErrors()
            ->assertSet('step', 'pin');

        $this->assertNull(Cache::get('recovery_otp_'.$user->id));
    }

    public function test_a_fresh_code_is_six_digits_and_resets_the_guess_budget(): void
    {
        Notification::fake();

        $user = $this->restrictedUser();
        $this->actingAs($user);
        Cache::put('recovery_otp_fails_'.$user->id, 4, now()->addMinutes(10));

        Volt::test('security.recover')->call('sendOtp');

        $code = Cache::get('recovery_otp_'.$user->id);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);
        $this->assertNull(Cache::get('recovery_otp_fails_'.$user->id));
        Notification::assertSentTo($user, EmailOtpNotification::class);
    }
}
