<?php

namespace Tests\Feature\Auth;

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
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasErrors(['last_name']);

        Volt::test('auth.register')
            ->set('first_name', '')
            ->set('last_name', 'Lovelace')
            ->set('email', 'halfname@example.com')
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
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        Event::assertDispatchedTimes(Registered::class, 1);
    }
}
