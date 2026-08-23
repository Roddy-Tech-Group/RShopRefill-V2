<?php

namespace Tests\Feature\Auth;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Registration now captures a country + phone number, stores the phone as E.164,
 * and lands on an in-modal success step (rather than redirecting to the
 * dashboard) so the customer is told to verify their email. The verification
 * email itself is sent through the branded Communication Platform.
 */
class RegistrationPhoneCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The live registration path (the global auth modal) is gated on the
        // signup feature flag.
        SiteSetting::put('features.signup_enabled', 'on', 'features');
        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function validFields(): array
    {
        return [
            'regName' => 'Ada Lovelace',
            'regEmail' => 'ada@example.com',
            'regCountry' => 'NG',
            'regPhone' => '8031234567',
            'regPassword' => 'Password123!',
            'regPasswordConfirmation' => 'Password123!',
            'regGender' => 'female',
            'regAcceptedTerms' => true,
        ];
    }

    public function test_registration_captures_country_and_stores_phone_as_e164(): void
    {
        $component = Volt::test('auth.auth-modal');

        foreach ($this->validFields() as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasNoErrors();

        $this->assertAuthenticated();

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame('+2348031234567', $user->phone, 'Nigerian trunk-0 number should be stored as E.164.');
        $this->assertSame('NG', $user->country);
    }

    public function test_registration_shows_the_success_step_instead_of_redirecting(): void
    {
        $component = Volt::test('auth.auth-modal');

        foreach ($this->validFields() as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertDispatched('registration-success');
    }

    public function test_registration_rejects_an_invalid_phone_number(): void
    {
        $component = Volt::test('auth.auth-modal');

        foreach (array_merge($this->validFields(), ['regPhone' => '123']) as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasErrors('regPhone');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_requires_a_valid_country(): void
    {
        $component = Volt::test('auth.auth-modal');

        foreach (array_merge($this->validFields(), ['regCountry' => 'ZZ']) as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasErrors('regCountry');
        $this->assertGuest();
    }

    public function test_a_phone_number_cannot_register_twice(): void
    {
        User::factory()->create(['phone' => '+2348031234567']);

        $component = Volt::test('auth.auth-modal');

        foreach ($this->validFields() as $field => $value) {
            $component->set($field, $value);
        }

        // Same national number, entered with the trunk 0 this time, normalises
        // to the same E.164 line and must be refused.
        $component->set('regPhone', '08031234567')
            ->call('register')
            ->assertHasErrors('regPhone');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_verification_email_is_sent_through_the_communication_platform(): void
    {
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        // The branded platform records a queued communication_logs row per
        // channel; the default Laravel notification would not.
        $this->assertDatabaseHas('communication_logs', [
            'category' => 'email_verification',
        ]);
    }
}
