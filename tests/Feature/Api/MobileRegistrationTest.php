<?php

namespace Tests\Feature\Api;

use App\Domain\Risk\Services\RiskEngine;
use App\Models\PersonalAccessToken;
use App\Models\SiteSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The mobile app's sign-up. Pinned here: it is the website's register wizard
 * by another road (the same rules, the same one-phone-one-account check, the
 * same verification email), each page is checked as it is left, and a new
 * account comes out signed in on the phone it was made on.
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

    public function test_a_new_account_is_made_and_signed_in_on_the_phone(): void
    {
        Event::fake([Registered::class]);

        $this->app()->postJson(route('api.mobile.register.store'), $this->answers())
            ->assertOk()
            ->assertJsonPath('status', 'signed_in')
            ->assertJsonPath('user.name', 'Amina Bello')
            ->assertJsonPath('user.email_verified', false);

        $user = User::where('email', 'amina@example.com')->sole();
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('NG', $user->country);
        $this->assertNotNull($user->profile_completed_at);
        Event::assertDispatchedTimes(Registered::class, 1);

        // The phone it was made on is the account's first device, trusted at birth.
        $this->assertTrue(TrustedDevice::where('device_uuid', $this->phone)->sole()->isTrusted());
        $this->assertSame(1, PersonalAccessToken::count());
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
