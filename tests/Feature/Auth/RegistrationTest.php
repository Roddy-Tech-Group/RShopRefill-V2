<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    /**
     * Registration is refused without consent, and refused SERVER-side. A
     * `required` attribute or a disabled button is advisory; the `accepted`
     * rule is what actually stops it.
     */
    public function test_registration_requires_accepting_the_terms(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test User')
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
            ->set('name', 'Test User')
            ->set('email', 'once@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        Event::assertDispatchedTimes(Registered::class, 1);
    }
}
