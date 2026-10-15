<?php

namespace Tests\Feature\Api;

use App\Domain\Risk\Services\RiskEngine;
use App\Models\CommunicationLog;
use App\Models\PersonalAccessToken;
use App\Models\SiteSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The mobile app's sign-up. Pinned here: it is the website's register wizard
 * by another road (the same rules, the same one-phone-one-account check), each
 * page is checked as it is left, and a new account confirms its email with an
 * emailed code before it is signed in on the phone it was made on.
 */
class MobileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Location::shouldReceive('get')->andReturn(false);
        Cache::flush();
        SiteSetting::put('features.signup_enabled', 'on', 'features');
        $this->mock(RiskEngine::class)->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        $this->phone = (string) Str::uuid();
    }

    private function app(): static
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Installation-ID' => $this->phone]);
    }

    /** @return array<string, mixed> */
    private function answers(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Amina',
            'last_name' => 'Bello',
            'email' => 'amina@example.com',
            'country' => 'NG',
            'phone' => '8031234567',
            'gender' => 'female',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'terms' => true,
            'adult' => true,
        ], $overrides);
    }

    /** Emails sent under one correlation ID (one log row per channel, so the email channel alone). */
    private function emailsSent(string $correlationId): int
    {
        return CommunicationLog::query()
            ->where('correlation_id', $correlationId)
            ->where('channel', 'email')
            ->count();
    }

    public function test_a_new_account_confirms_its_email_with_a_code_before_it_is_signed_in(): void
    {
        $response = $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertStatus(202)
            ->assertJsonPath('status', 'verification_required')
            ->assertJsonPath('purpose', 'email')
            ->assertJsonPath('masked_email', 'am***@example.com')
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'amina@example.com')->sole();
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('NG', $user->country);
        $this->assertNotNull($user->profile_completed_at);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, PersonalAccessToken::count());

        // The code goes out in place of the website's link, not beside it.
        $this->assertSame(1, $this->emailsSent('otp_email_verification_'.$user->id));
        $this->assertSame(0, $this->emailsSent('email_verification_'.$user->id));
        $this->assertSame(0, $this->emailsSent('welcome_'.$user->id));

        $this->app()->postJson(route('api.mobile.auth.verify-device'), [
            'challenge' => $response->json('challenge'),
            'code' => Cache::get('device_otp_'.$user->id),
        ])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in')
            ->assertJsonPath('user.name', 'Amina Bello')
            ->assertJsonPath('user.email_verified', true);

        // Confirmed by the code, and welcomed for it, as the website's link does.
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, $this->emailsSent('welcome_'.$user->id));

        // The phone it was made on is the account's first device, trusted at birth.
        $this->assertTrue(TrustedDevice::where('device_uuid', $this->phone)->sole()->isTrusted());
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_wrong_code_signs_nobody_in_and_confirms_nothing(): void
    {
        $challenge = $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertStatus(202)
            ->json('challenge');
        $user = User::where('email', 'amina@example.com')->sole();
        $wrong = Cache::get('device_otp_'.$user->id) === '000000' ? '111111' : '000000';

        $this->app()->postJson(route('api.mobile.auth.verify-device'), ['challenge' => $challenge, 'code' => $wrong])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_registered_email_is_sent_to_sign_in_from_the_first_page(): void
    {
        User::factory()->create(['email' => 'amina@example.com']);

        // A conflict, not a field error: the app takes its owner to Sign in.
        $this->app()->postJson(route('api.mobile.register.check'), [
            'step' => 1,
            'first_name' => 'Amina',
            'last_name' => 'Bello',
            'email' => 'Amina@Example.com',
        ])->assertStatus(409)
            ->assertJsonPath('account_exists', true)
            ->assertJsonPath('message', 'An account with this email already exists. Sign in instead.');

        $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertStatus(409)
            ->assertJsonPath('account_exists', true);
        $this->assertSame(1, User::count());

        $this->app()->postJson(route('api.mobile.register.check'), [
            'step' => 1,
            'first_name' => 'Amina',
            'last_name' => 'Bello',
            'email' => 'someone-new@example.com',
        ])->assertNoContent();
    }

    public function test_a_registered_phone_is_caught_on_the_second_page(): void
    {
        User::factory()->create(['phone' => '+2348031234567']);

        // Typed with the trunk 0, it is still the same line.
        $this->app()->postJson(route('api.mobile.register.check'), [
            'step' => 2,
            'country' => 'NG',
            'phone' => '08031234567',
            'gender' => 'female',
        ])->assertStatus(422)->assertJsonPath('errors.phone.0', __('auth.phone_taken'));
    }

    public function test_a_number_that_is_not_valid_for_the_country_is_refused(): void
    {
        $this->app()->postJson(route('api.mobile.register.check'), [
            'step' => 2,
            'country' => 'NG',
            'phone' => '123',
            'gender' => 'female',
        ])->assertStatus(422)
            // Words, not the raw "validation.phone" key the rule asks for.
            ->assertJsonPath('errors.phone.0', 'The phone field must be a valid number for the selected country.');
    }

    public function test_the_terms_and_the_age_must_both_be_accepted(): void
    {
        $this->app()->postJson(route('api.mobile.register.store'), $this->answers(['terms' => false, 'adult' => false]))
            ->assertStatus(422)
            ->assertJsonPath('errors.terms.0', 'Please accept the Terms of Service and Privacy Policy to continue.')
            ->assertJsonPath('errors.adult.0', 'Please certify that you are 18 years of age or older to continue.');

        $this->assertSame(0, User::count());
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->app()->postJson(route('api.mobile.register.store'), $this->answers(['password_confirmation' => 'something-else']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_sign_up_is_closed_when_the_site_closes_it(): void
    {
        SiteSetting::put('features.signup_enabled', 'off', 'features');

        $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertStatus(403)
            ->assertJsonPath('message', __('auth.registrations_closed'));
        $this->assertSame(0, User::count());
    }

    public function test_sign_up_shares_the_webs_five_a_minute_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->app()->postJson(route('api.mobile.register.store'), $this->answers(['terms' => false]))
                ->assertStatus(422);
        }

        $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertStatus(429);
        $this->assertSame(0, User::count());
    }
}
