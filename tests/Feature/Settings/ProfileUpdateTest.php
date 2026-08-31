<?php

namespace Tests\Feature\Settings;

use App\Domain\Communication\Mail\Security\EmailChangedMail;
use App\Domain\Communication\Mail\Security\VerifyEmailMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/dashboard/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_account_country_can_be_saved_and_persists(): void
    {
        $user = User::factory()->create(['country' => null]);

        $this->actingAs($user);

        // Stored as the ISO code, the same shape registration writes, so every
        // ISO-comparing reader (Smile ID's product routing) matches it.
        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'CM')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertEquals('CM', $user->refresh()->country);
    }

    public function test_account_country_rejects_the_display_name_now_that_iso_codes_are_stored(): void
    {
        $user = User::factory()->create(['country' => null]);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'Cameroon')
            ->call('updateProfileInformation')
            ->assertHasErrors('country');
    }

    public function test_a_national_phone_number_is_stored_as_e164(): void
    {
        $user = User::factory()->create(['country' => null, 'phone' => null]);

        $this->actingAs($user);

        // Typed with the trunk 0, as a Nigerian customer would.
        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('+2348031234567', $user->refresh()->phone);
    }

    public function test_a_phone_number_belonging_to_another_account_is_refused(): void
    {
        User::factory()->create(['phone' => '+2348031234567']);
        $user = User::factory()->create(['country' => null, 'phone' => null]);

        $this->actingAs($user);

        // The national form of a number another account already holds: the
        // check runs on the canonical E.164 value, so the two are seen as the
        // same line. One verified line, one account.
        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->call('updateProfileInformation')
            ->assertHasErrors('phone');

        $this->assertNull($user->refresh()->phone);
    }

    public function test_keeping_your_own_unchanged_phone_number_is_not_a_duplicate(): void
    {
        $user = User::factory()->create(['country' => 'NG', 'phone' => '+2348031234567']);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', 'Renamed Person')
            ->set('email', $user->email)
            ->set('country', 'NG')
            ->set('phone', '+2348031234567')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('+2348031234567', $user->refresh()->phone);
    }

    public function test_a_number_that_is_not_valid_for_the_chosen_country_is_refused(): void
    {
        $user = User::factory()->create(['country' => null, 'phone' => null]);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'NG')
            ->set('phone', '12')
            ->call('updateProfileInformation')
            ->assertHasErrors('phone');
    }

    public function test_the_phone_can_be_cleared(): void
    {
        $user = User::factory()->create(['country' => 'NG', 'phone' => '+2348031234567']);

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'NG')
            ->set('phone', '')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertNull($user->refresh()->phone);
    }

    public function test_account_country_rejects_values_outside_the_country_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', $user->name)
            ->set('email', $user->email)
            ->set('country', 'Atlantis')
            ->call('updateProfileInformation')
            ->assertHasErrors('country');
    }

    public function test_changing_the_email_verifies_the_new_address_and_warns_the_old_one(): void
    {
        $sent = [];
        $this->mock(CommunicationManager::class, function (MockInterface $mock) use (&$sent): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args) use (&$sent): void {
                $sent[] = $args;
            });
        });

        $user = User::factory()->create(['email' => 'old@example.test', 'name' => 'Ada Okafor']);
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', 'Ada Okafor')
            ->set('email', 'new@example.test')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('new@example.test', $user->email);
        $this->assertNull($user->email_verified_at);

        // Verification link to the new address.
        $verification = collect($sent)->first(fn (array $args) => ($args[4] ?? null) instanceof VerifyEmailMail);
        $this->assertNotNull($verification);

        // Security notice to the old address, naming the new one only in masked form.
        $notice = collect($sent)->first(fn (array $args) => ($args[4] ?? null) instanceof EmailChangedMail);
        $this->assertNotNull($notice);
        $this->assertSame('old@example.test', $notice[9]);
        $this->assertSame('ne**@example.test', $notice[4]->newEmailMasked);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_close_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('closeAccount');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        // Closure never deletes the row: the account is marked and anonymised
        // after the grace period (see AccountClosureTest).
        $this->assertNotNull($user->fresh());
        $this->assertTrue($user->fresh()->hasRequestedClosure());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_close_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Volt::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('closeAccount');

        $response->assertHasErrors(['password']);

        $this->assertFalse($user->fresh()->hasRequestedClosure());
    }
}
