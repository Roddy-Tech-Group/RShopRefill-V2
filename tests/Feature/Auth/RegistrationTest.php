<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        // Registration is a real page again - see the note in AuthenticationTest.
        $this->withoutVite()->get('/register')
            ->assertOk()
            ->assertSee(__('auth.sign_in'))
            ->assertSee(__('auth.sign_up'));
    }

    public function test_new_users_can_register(): void
    {
        $response = Volt::test('auth.register')
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('email', 'test@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'name' => 'Test User']);
    }

    /**
     * The form asks for a first and a last name, not one "Full Name" box.
     *
     * users.name is still a single column - every screen that greets a customer
     * reads it - so the two fields are joined back into it on submit. The split
     * is what the customer types, not what the schema holds.
     */
    public function test_the_name_is_asked_for_in_two_fields_and_stored_as_one(): void
    {
        $blade = File::get(resource_path('views/livewire/auth/register.blade.php'));

        $this->assertStringContainsString('wire:model="first_name"', $blade);
        $this->assertStringContainsString('wire:model="last_name"', $blade);
        $this->assertStringNotContainsString('wire:model="name"', $blade);
        $this->assertStringNotContainsString('Full Name', $blade);

        // The autofill tokens browsers and password managers actually match on.
        $this->assertStringContainsString('autocomplete="given-name"', $blade);
        $this->assertStringContainsString('autocomplete="family-name"', $blade);

        // One row at every width - no grid-cols-1 that only splits at sm.
        $this->assertStringContainsString('<div class="grid grid-cols-2 gap-3">', $blade);
        $this->assertStringNotContainsString('grid-cols-1 gap-3 sm:grid-cols-2', $blade);

        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'ada@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    }

    /** Both halves are required - a first name alone is not a name here. */
    public function test_both_halves_of_the_name_are_required(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', '')
            ->set('email', 'halfname@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors(['last_name']);

        Volt::test('auth.register')
            ->set('first_name', '')
            ->set('last_name', 'Lovelace')
            ->set('email', 'halfname@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors(['first_name']);

        $this->assertDatabaseMissing('users', ['email' => 'halfname@example.com']);
        $this->assertGuest();
    }

    /**
     * Registration is refused without consent, and refused SERVER-side. A
     * `required` attribute or a disabled button is advisory; the `accepted`
     * rule is what actually stops it.
     */
    public function test_registration_requires_accepting_the_terms(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('email', 'consent@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', false)
            ->call('register')
            ->assertHasErrors(['terms']);

        $this->assertDatabaseMissing('users', ['email' => 'consent@example.com']);
        $this->assertGuest();
    }

    /** The consent links have to reach the real documents, not just say so. */
    public function test_the_consent_links_point_at_the_legal_pages(): void
    {
        $html = $this->withoutVite()->get('/register')->assertOk()->getContent();

        $this->assertStringContainsString('I agree to the', $html);
        $this->assertStringContainsString('href="'.route('shop.terms').'"', $html);
        $this->assertStringContainsString('href="'.route('shop.privacy').'"', $html);
    }

    /**
     * The Registered event fires ONCE. It used to be dispatched twice - at the
     * User::create() call and again a few lines later - so every signup sent two
     * verification emails.
     */
    public function test_registration_dispatches_the_registered_event_once(): void
    {
        Event::fake([Registered::class]);

        Volt::test('auth.register')
            ->set('first_name', 'Test')
            ->set('last_name', 'User')
            ->set('email', 'once@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        Event::assertDispatchedTimes(Registered::class, 1);
    }

    /**
     * The country is not a field of its own. It is the dial code chosen on the
     * phone row, so picking +234 IS picking Nigeria, and the number typed
     * beside it is stored as E.164 rather than as whatever the customer typed.
     * A trunk 0 is tolerated on the way in and stripped on the way to the
     * column, which is the only reason a national-number unique rule could
     * never have caught the same line twice.
     */
    public function test_the_dial_code_sets_the_country_and_the_number_is_stored_as_e164(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'e164@example.com')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        $user = User::where('email', 'e164@example.com')->firstOrFail();

        $this->assertSame('+2348031234567', $user->phone, 'the trunk 0 should be stripped into E.164');
        $this->assertSame('NG', $user->country, 'the dial code should have set the country');
        $this->assertSame('female', $user->gender);
    }

    /**
     * The page asks for the same three the sign-up modal does. It was rebuilt
     * as a real page without them, which left the account a customer got
     * depending on which door they came through.
     */
    public function test_the_country_phone_and_gender_are_all_required(): void
    {
        foreach ([
            'country' => '',
            'phone' => '',
            'gender' => '',
        ] as $field => $blank) {
            Volt::test('auth.register')
                ->set('first_name', 'Ada')
                ->set('last_name', 'Lovelace')
                ->set('email', 'missing@example.com')
                ->set('country', 'NG')
                ->set('phone', '8031234567')
                ->set('gender', 'female')
                ->set($field, $blank)
                ->set('password', 'password')
                ->set('password_confirmation', 'password')
                ->set('terms', true)
                ->call('register')
                ->assertHasErrors($field);
        }

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'missing@example.com']);
    }

    /** The number is checked against the chosen country's numbering plan. */
    public function test_a_number_that_is_not_valid_for_the_country_is_refused(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'badphone@example.com')
            ->set('country', 'NG')
            ->set('phone', '123')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('phone');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'badphone@example.com']);
    }

    /** One line, one account, whichever form the second attempt comes through. */
    public function test_a_phone_number_cannot_register_twice(): void
    {
        User::factory()->create(['phone' => '+2348031234567']);

        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'second@example.com')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    /**
     * The form collects everything an account needs, so a signup made here is
     * complete on arrival and never meets the completion step a Google sign-in
     * lands on.
     */
    public function test_a_signup_from_this_page_is_a_complete_profile(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'complete@example.com')
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        $user = User::where('email', 'complete@example.com')->firstOrFail();

        $this->assertNotNull($user->profile_completed_at);
        $this->assertFalse($user->needsProfileCompletion());

        // And end to end: EnsureProfileCompleted holds anyone who still owes
        // the step, so reaching the dashboard proves this signup owes nothing.
        $this->actingAs($user)
            ->withoutVite()
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * A Google signup is the other door, and it is untouched: Google returns
     * no country, phone or gender, so that account still owes the completion
     * step and is held there until it answers them.
     */
    public function test_a_google_signup_still_owes_the_completion_step(): void
    {
        $google = User::factory()->create([
            'google_id' => 'g-123',
            'password' => null,
            'country' => null,
            'phone' => null,
            'gender' => null,
            'profile_completed_at' => null,
        ]);

        $this->assertTrue($google->needsProfileCompletion());

        $this->actingAs($google)
            ->withoutVite()
            ->get(route('dashboard'))
            ->assertRedirect(route('auth.complete-profile'));
    }

    /** The page offers the same three controls the modal does. */
    public function test_the_page_renders_the_phone_row_and_the_gender_choices(): void
    {
        $html = $this->withoutVite()->get('/register')->assertOk()->getContent();

        // One row: the dial-code button and the national number beside it.
        $this->assertTrue(str_contains($html, 'aria-label="'.e(__('auth.modal_dial_code')).'"'), 'the dial code picker is missing');
        $this->assertTrue(str_contains($html, 'autocomplete="tel-national"'), 'the phone input is missing');
        $this->assertTrue(str_contains($html, 'wire:model="phone"'), 'the phone field is not bound');

        // And the three gender choices, each setting the one field.
        foreach (['male', 'female', 'other'] as $choice) {
            $this->assertTrue(
                str_contains($html, 'wire:click="$set(\'gender\', \''.$choice.'\')"'),
                "the {$choice} option is missing"
            );
        }
    }
}
