<?php

namespace Tests\Feature\Api;

use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Security\Events\OtpRequested;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * A forgotten password, reset in the mobile app with an emailed code where the
 * website sends a link. Pinned here: the reset form never says whether an
 * address has an account, a code is burned by five wrong guesses, what a right
 * code buys works once and only on the phone that entered it, and the new
 * password, held to the website's rule, ends every sign-in made with the old.
 */
class MobilePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Lagos-rain-at-7-2026';

    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Location::shouldReceive('get')->andReturn(false);
        Cache::flush();
        $this->mock(RiskEngine::class)->shouldReceive('evaluateLoginRisk')->andReturn('LOW');

        $this->phone = (string) Str::uuid();
    }

    private function app(?string $installationId = null): static
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            'X-Installation-ID' => $installationId ?? $this->phone,
        ]);
    }

    private function codeFor(string $email): ?string
    {
        return Cache::get('password_reset_code_'.hash('sha256', $email));
    }

    /** As if the wait between codes had passed. */
    private function waitIsOver(string $email): void
    {
        Cache::forget('password_reset_wait_'.hash('sha256', $email));
    }

    private function forgot(string $email): TestResponse
    {
        return $this->app()->postJson(route('api.mobile.password.forgot'), ['email' => $email]);
    }

    private function verify(string $email, ?string $code = null, ?string $installationId = null): TestResponse
    {
        return $this->app($installationId)->postJson(route('api.mobile.password.verify'), [
            'email' => $email,
            'code' => $code ?? $this->codeFor($email),
        ]);
    }

    private function reset(string $ticket, ?string $installationId = null, string $password = self::NEW_PASSWORD): TestResponse
    {
        return $this->app($installationId)->postJson(route('api.mobile.password.reset'), [
            'ticket' => $ticket,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_a_forgotten_password_is_reset_with_an_emailed_code(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email' => 'amina@example.com']);

        $this->forgot('Amina@Example.com')
            ->assertStatus(202)
            ->assertJsonPath('masked_email', 'am***@example.com')
            ->assertJsonPath('expires_in', 600)
            ->assertJsonPath('resend_in', 60);

        Event::assertDispatched(OtpRequested::class, fn (OtpRequested $event) => $event->context === 'password_reset'
            && $event->user->is($user)
            && $event->otp === $this->codeFor('amina@example.com'));

        $ticket = $this->verify('amina@example.com')->assertOk()->json('ticket');

        $this->reset($ticket)
            ->assertOk()
            ->assertJsonPath('message', 'Your password has been reset.')
            ->assertJsonPath('email', 'amina@example.com');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        // The new password signs in; the old one no longer does.
        $this->app()->postJson(route('api.mobile.auth.login'), ['email' => 'amina@example.com', 'password' => 'password'])
            ->assertStatus(422);
        $this->app()->postJson(route('api.mobile.auth.login'), ['email' => 'amina@example.com', 'password' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('status', 'signed_in');
    }

    public function test_an_address_with_no_account_is_answered_as_one_with_an_account_is(): void
    {
        Event::fake([OtpRequested::class]);
        User::factory()->create(['email' => 'amina@example.com']);

        $known = $this->forgot('amina@example.com')->assertStatus(202)->json();
        $unknown = $this->forgot('nobody@example.com')->assertStatus(202)->json();

        $this->assertSame(
            array_diff_key($known, ['masked_email' => true]),
            array_diff_key($unknown, ['masked_email' => true]),
        );
        Event::assertDispatchedTimes(OtpRequested::class, 1);

        // The same wait for both, too.
        $this->forgot('amina@example.com')->assertStatus(429);
        $this->forgot('nobody@example.com')->assertStatus(429);

        // A code exists for the address with no account, but no one was sent it,
        // and matching it proves nothing.
        $this->verify('nobody@example.com')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Invalid or expired code.');
    }

    public function test_the_code_is_burned_after_five_wrong_guesses(): void
    {
        Event::fake([OtpRequested::class]);
        User::factory()->create(['email' => 'amina@example.com']);
        $this->forgot('amina@example.com');
        $right = $this->codeFor('amina@example.com');
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach (range(1, 4) as $guess) {
            $this->verify('amina@example.com', $wrong)->assertJsonPath('errors.code.0', 'Invalid or expired code.');
        }

        $this->verify('amina@example.com', $wrong)
            ->assertJsonPath('errors.code.0', 'Too many incorrect attempts. Please request a new code.');
        $this->verify('amina@example.com', $right)->assertStatus(422);
    }

    public function test_a_proven_code_buys_one_reset_on_the_phone_that_proved_it(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email' => 'amina@example.com']);

        $this->forgot('amina@example.com');
        $ticket = $this->verify('amina@example.com')->json('ticket');

        // Another phone cannot use it, and trying spends it.
        $this->reset($ticket, (string) Str::uuid())->assertStatus(422)->assertJsonValidationErrors('ticket');
        $this->reset($ticket)->assertStatus(422);

        $this->waitIsOver('amina@example.com');
        $this->forgot('amina@example.com');
        $ticket = $this->verify('amina@example.com')->json('ticket');

        $this->reset($ticket)->assertOk();
        $this->reset($ticket, password: 'Another-new-one-2026')->assertStatus(422);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_the_new_password_is_held_to_the_websites_rule(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->create(['email' => 'amina@example.com']);
        $this->forgot('amina@example.com');
        $ticket = $this->verify('amina@example.com')->json('ticket');

        $this->reset($ticket, password: 'short')->assertStatus(422)->assertJsonValidationErrors('password');
        $this->reset($ticket, password: '12345678')
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'This password is too easy to guess. Avoid common words and patterns like aaaa or 1234.');
        // The account's own name and email count, though the form only sends the ticket.
        $this->reset($ticket, password: 'Amina-at-home-2026')
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Your password must not contain your name or email address.');
        $this->app()->postJson(route('api.mobile.password.reset'), [
            'ticket' => $ticket,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'something-else-entirely',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // A refused password does not spend the ticket.
        $this->reset($ticket)->assertOk();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_reset_ends_every_sign_in_made_with_the_old_password(): void
    {
        Event::fake([OtpRequested::class]);
        User::factory()->create(['email' => 'amina@example.com']);

        $token = $this->app()
            ->postJson(route('api.mobile.auth.login'), ['email' => 'amina@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');
        $this->app()->withToken($token)->getJson(route('api.mobile.me'))->assertOk();

        $this->forgot('amina@example.com');
        $this->reset($this->verify('amina@example.com')->json('ticket'))->assertOk();

        // Each real request reads the account afresh; the test's guard would
        // otherwise still hold the one it read before the reset.
        $this->app['auth']->forgetGuards();
        $this->app()->withToken($token)->getJson(route('api.mobile.me'))->assertStatus(401);
    }

    public function test_the_code_confirms_an_email_that_was_never_confirmed(): void
    {
        Event::fake([OtpRequested::class]);
        $user = User::factory()->unverified()->create(['email' => 'amina@example.com']);

        $this->forgot('amina@example.com');
        $this->verify('amina@example.com')->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_banned_account_is_sent_nothing(): void
    {
        Event::fake([OtpRequested::class]);
        User::factory()->create(['email' => 'amina@example.com', 'banned_at' => now()]);

        $this->forgot('amina@example.com')->assertStatus(202);

        Event::assertNotDispatched(OtpRequested::class);
        $this->verify('amina@example.com')->assertStatus(422);
    }
}
