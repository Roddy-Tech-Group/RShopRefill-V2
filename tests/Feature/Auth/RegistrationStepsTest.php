<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\PhoneCountries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Sign-up asks for eleven things. It now asks across three pages.
 *
 * The split is presentation. register() validates the whole form before it
 * writes anything, so the pages are a way to show the questions a few at a
 * time and never the gate: a caller who sets step to 3 and submits gets the
 * same refusal as one who filled nothing in.
 */
class RegistrationStepsTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, valid set of answers. */
    private function answers(): array
    {
        return [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'country' => 'NG',
            'phone' => '8031234567',
            'gender' => 'female',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
            'terms' => true,
        ];
    }

    public function test_it_opens_on_the_first_page(): void
    {
        Volt::test('auth.register')->assertSet('step', 1);
    }

    /** Each page only asks for its own share. */
    public function test_the_pages_carry_their_own_fields(): void
    {
        $component = Volt::test('auth.register');

        $one = $component->html();
        $this->assertStringContainsString('wire:model="first_name"', $one);
        $this->assertStringContainsString('wire:model="email"', $one);
        $this->assertStringNotContainsString('wire:model="phone"', $one);
        $this->assertStringNotContainsString('wire:model="password"', $one);

        $two = $component->set('step', 2)->html();
        $this->assertStringContainsString('wire:model="phone"', $two);
        $this->assertStringNotContainsString('wire:model="first_name"', $two);
        $this->assertStringNotContainsString('wire:model="password"', $two);

        $three = $component->set('step', 3)->html();
        $this->assertStringContainsString('wire:model="password"', $three);
        $this->assertStringContainsString('wire:model="terms"', $three);
        $this->assertStringNotContainsString('wire:model="phone"', $three);
    }

    /** A page will not hand over until its own answers hold up. */
    public function test_the_first_page_holds_until_its_fields_are_valid(): void
    {
        Volt::test('auth.register')
            ->call('next')
            ->assertHasErrors(['first_name', 'last_name', 'email'])
            ->assertSet('step', 1);
    }

    public function test_the_second_page_holds_until_its_fields_are_valid(): void
    {
        Volt::test('auth.register')
            ->set('step', 2)
            ->set('phone', '')
            ->set('gender', '')
            ->call('next')
            ->assertHasErrors(['phone', 'gender'])
            ->assertSet('step', 2);
    }

    /** And moves on once they do. */
    public function test_valid_answers_advance_the_page(): void
    {
        $answers = $this->answers();

        Volt::test('auth.register')
            ->set('first_name', $answers['first_name'])
            ->set('last_name', $answers['last_name'])
            ->set('email', $answers['email'])
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('country', $answers['country'])
            ->set('phone', $answers['phone'])
            ->set('gender', $answers['gender'])
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 3);
    }

    /** Going back keeps what was typed: it is a button, not a fresh request. */
    public function test_going_back_keeps_the_answers(): void
    {
        Volt::test('auth.register')
            ->set('first_name', 'Ada')
            ->set('last_name', 'Lovelace')
            ->set('email', 'ada@example.com')
            ->call('next')
            ->assertSet('step', 2)
            ->call('back')
            ->assertSet('step', 1)
            ->assertSet('first_name', 'Ada')
            ->assertSet('email', 'ada@example.com');
    }

    /** The pages have ends. */
    public function test_back_stops_at_the_first_page(): void
    {
        Volt::test('auth.register')->call('back')->assertSet('step', 1);
    }

    /**
     * The assertion that matters. Jumping to the last page and submitting is
     * not a way past the questions on the pages before it.
     */
    public function test_skipping_to_the_last_page_does_not_skip_validation(): void
    {
        Volt::test('auth.register')
            ->set('step', 3)
            ->set('password', 'Str0ng-Passw0rd!')
            ->set('password_confirmation', 'Str0ng-Passw0rd!')
            ->set('terms', true)
            ->call('register')
            // country is not in this list: it defaults to a real ISO code so the
            // dial-code picker opens somewhere sensible, so it is never blank.
            ->assertHasErrors(['first_name', 'last_name', 'email', 'phone', 'gender']);

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * A number already on an account is caught on its own page, rather than
     * after the customer has chosen a password.
     */
    public function test_a_used_number_is_caught_on_the_page_that_asks_for_it(): void
    {
        User::factory()->create([
            'phone' => '+2348031234567',
            'country' => 'NG',
        ]);

        Volt::test('auth.register')
            ->set('step', 2)
            ->set('country', 'NG')
            ->set('phone', '8031234567')
            ->set('gender', 'female')
            ->call('next')
            ->assertHasErrors('phone')
            ->assertSet('step', 2);
    }

    /** Walked through properly, it still makes the account. */
    public function test_the_whole_walk_registers(): void
    {
        $answers = $this->answers();

        $component = Volt::test('auth.register');

        foreach ($answers as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('next')->call('next')->assertSet('step', 3)
            ->call('register')
            ->assertHasNoErrors();

        $user = User::where('email', $answers['email'])->sole();

        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('female', $user->gender);
        $this->assertNotNull($user->profile_completed_at);
        $this->assertAuthenticatedAs($user);
    }

    /** The progress bar says where you are. */
    public function test_the_progress_shows_the_current_page(): void
    {
        $component = Volt::test('auth.register');

        $this->assertStringContainsString('1/3', $component->html());
        $this->assertStringContainsString('3/3', $component->set('step', 3)->html());
    }

    /**
     * The country defaults so the dial-code picker opens somewhere sensible
     * rather than empty.
     */
    public function test_the_country_starts_at_the_default(): void
    {
        Volt::test('auth.register')->assertSet('country', PhoneCountries::DEFAULT_ISO);
    }
}
