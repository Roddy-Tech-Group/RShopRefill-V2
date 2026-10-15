<?php

namespace Tests\Feature\Auth;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Auth\Services\GoogleAuthService;
use App\Http\Middleware\EnsureProfileCompleted;
use App\Models\Admin;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Google returns a name, an email and a picture, so an account created from it
 * has no country, no phone and no gender - the three things the register wizard
 * insists on. These tests pin the completion step that closes that gap: a fresh
 * Google account cannot browse until it supplies them, an account that already
 * finished signing up never sees the page, and the details are held to exactly
 * the rules registration applies.
 */
class GoogleSignupCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_google_signup_is_held_on_the_completion_step(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('auth.complete-profile'));
    }

    public function test_the_hold_covers_the_storefront_not_just_the_dashboard(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user)
            ->get('/checkout')
            ->assertRedirect(route('auth.complete-profile'));
    }

    public function test_a_returning_google_user_with_a_finished_account_signs_in_untouched(): void
    {
        // Scenario 1 in GoogleAuthService: already linked, already complete.
        $user = User::factory()->create([
            'google_id' => '1234567890',
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'male',
        ]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_the_completion_page_itself_is_reachable_while_held(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user)
            ->get(route('auth.complete-profile'))
            ->assertOk();
    }

    public function test_a_finished_account_is_sent_away_from_the_completion_page(): void
    {
        $user = User::factory()->create([
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        $this->actingAs($user)
            ->get(route('auth.complete-profile'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_second_submission_cannot_rewrite_details_the_first_one_saved(): void
    {
        // The real shape of this risk: the form is open, submitted once, then
        // submitted again from a stale tab or a double click. mount() guards the
        // page, but a Livewire call needs its own guard on the write.
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        $component = Volt::test('auth.complete-profile');

        $component
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'female')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasNoErrors();

        // Same still-mounted component fires again with different answers.
        $component
            ->set('country', 'GH')
            ->set('phone', '241234567')
            ->set('gender', 'male')
            ->call('save');

        $user->refresh();

        $this->assertSame('NG', $user->country);
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('female', $user->gender);
    }

    public function test_livewire_calls_are_never_intercepted_while_the_hold_is_on(): void
    {
        // The bug this pins shipped to production: the exemption was the literal
        // 'livewire/*', but Livewire 4 fingerprints its endpoint to
        // livewire-<hash>/update, so the guard redirected every wire:click and
        // wire:submit on the completion form. The page appeared to reload and
        // render itself inside itself, and no control worked.
        //
        // Volt::test() calls the component directly and never crosses the
        // middleware, which is exactly why the rest of this file stayed green
        // while the form was broken. This asserts the middleware itself.
        $user = User::factory()->profileIncomplete()->create();

        $middleware = new EnsureProfileCompleted;
        $reached = false;

        $request = Request::create(Livewire::getUpdateUri(), 'POST');
        $request->setUserResolver(fn () => $user);

        $middleware->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response('ok');
        });

        $this->assertTrue($reached, 'The Livewire endpoint must reach the app, not be redirected by the profile hold.');
    }

    public function test_an_ordinary_page_is_still_held(): void
    {
        // The other half of the same guard: relaxing Livewire must not relax
        // everything else.
        $user = User::factory()->profileIncomplete()->create();

        $middleware = new EnsureProfileCompleted;
        $reached = false;

        $request = Request::create('/dashboard', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        $response = $middleware->handle($request, function () use (&$reached) {
            $reached = true;

            return new Response('ok');
        });

        $this->assertFalse($reached);
        $this->assertSame(route('auth.complete-profile'), $response->headers->get('Location'));
    }

    public function test_logging_out_is_still_possible_while_held(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect();

        $this->assertGuest();
    }

    public function test_completing_the_step_stores_the_details_and_lifts_the_hold(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('NG', $user->country);
        // Canonicalised: the customer typed the trunk 0.
        $this->assertSame('+2348031234567', $user->phone);
        $this->assertSame('male', $user->gender);
        $this->assertNotNull($user->profile_completed_at);
        $this->assertFalse($user->needsProfileCompletion());

        $this->get('/dashboard')->assertOk();
    }

    public function test_the_terms_must_be_accepted(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', false)
            ->call('save')
            ->assertHasErrors('acceptedTerms');

        $this->assertTrue($user->refresh()->needsProfileCompletion());
    }

    public function test_a_number_already_registered_to_another_account_is_refused(): void
    {
        User::factory()->create(['phone' => '+2348031234567']);
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertTrue($user->refresh()->needsProfileCompletion());
    }

    public function test_a_number_that_is_not_valid_for_the_chosen_country_is_refused(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '12')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasErrors('phone');
    }

    public function test_gender_and_country_are_required(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'Nigeria') // display name, not the ISO code
            ->set('phone', '08031234567')
            ->set('gender', '')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasErrors(['country', 'gender']);
    }

    public function test_opting_in_subscribes_the_account_to_the_newsletter(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->set('newsletterOptIn', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => $user->email,
            'status' => 'active',
        ]);
    }

    public function test_leaving_the_newsletter_box_unticked_subscribes_nobody(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('newsletter_subscribers', ['email' => $user->email]);
    }

    public function test_a_referral_code_typed_here_attributes_the_signup(): void
    {
        $referrer = User::factory()->create(['referral_code' => 'MAXWELL10']);
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->set('referral', 'MAXWELL10')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('referrals', [
            'referrer_id' => $referrer->id,
            'referred_user_id' => $user->id,
        ]);
    }

    public function test_an_unknown_referral_code_is_ignored_rather_than_blocking_signup(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->set('referral', 'NOSUCHCODE')
            ->call('save')
            ->assertHasNoErrors();

        // Registration completes; only the attribution is skipped.
        $this->assertFalse($user->refresh()->needsProfileCompletion());
        $this->assertDatabaseMissing('referrals', ['referred_user_id' => $user->id]);
    }

    public function test_the_welcome_email_waits_for_the_signup_to_be_completed(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        // The Google callback fires Registered on an account that still owes
        // the form: welcoming them here would greet an account they cannot use,
        // and would greet an abandoned signup that never becomes a customer.
        event(new Registered($user));

        $this->assertDatabaseMissing('communication_logs', [
            'correlation_id' => 'welcome_'.$user->getKey(),
        ]);
    }

    public function test_completing_the_signup_sends_the_welcome_email_once(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        event(new Registered($user));

        $this->actingAs($user);

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertHasNoErrors();

        // Exactly one welcome: the listener's correlation id is once-per-account
        // for life, so the callback's suppressed attempt and this one cannot
        // both land.
        $this->assertSame(1, CommunicationLog::query()
            ->where('correlation_id', 'welcome_'.$user->getKey())
            ->where('channel', 'email')
            ->count());
    }

    public function test_a_password_signup_still_waits_for_email_verification(): void
    {
        // Unchanged behaviour: an unverified credentials account is welcomed by
        // the Verified event, never by Registered.
        $user = User::factory()->unverified()->create();

        event(new Registered($user));

        $this->assertDatabaseMissing('communication_logs', [
            'correlation_id' => 'welcome_'.$user->getKey(),
        ]);
    }

    public function test_an_admin_session_is_never_touched_by_the_customer_hold(): void
    {
        // $request->user() resolves to an Admin on the admin guard, which knows
        // nothing about profile completion; the guard must not reach for it.
        $admin = Admin::create([
            'name' => 'Ops',
            'email' => 'ops@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->withoutVite();

        $this->actingAs($admin, 'admin')
            ->get('/admin/dashboard')
            ->assertSuccessful();
    }

    /**
     * A Socialite payload for the three GoogleAuthService scenarios.
     */
    private function googleUser(string $email, string $id = 'google-123'): SocialiteUser
    {
        // Google asserts the address is verified in the raw userinfo payload;
        // linking and sign-up both require that claim.
        return (new SocialiteUser)->setRaw(['email_verified' => true])->map([
            'id' => $id,
            'name' => 'Jane Doe',
            'nickname' => null,
            'email' => $email,
            'avatar' => 'https://lh3.googleusercontent.com/photo.jpg',
        ]);
    }

    public function test_a_password_account_signing_in_with_google_is_linked_not_held(): void
    {
        // Scenario 2: registered by password (so already complete), later
        // clicks "Sign in with Google" on the same address. Linking must never
        // send an established customer to the signup form.
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'google_id' => null,
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertFalse($result['is_new']);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertFalse($user->fresh()->needsProfileCompletion());

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_linking_google_claims_and_welcomes_an_account_that_never_verified_its_email(): void
    {
        // Registered by password, never clicked the verification link, then
        // signed in with Google. Google has proven the inbox, so the account
        // is theirs: verified now, welcomed once, and the unproven password
        // cleared so a pre-registerer keeps nothing. No signup form either;
        // the profile was already complete.
        $user = User::factory()->unverified()->create([
            'email' => 'jane@example.com',
            'google_id' => null,
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertTrue($result['claimed']);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertNull($user->fresh()->password);
        $this->assertFalse($user->fresh()->needsProfileCompletion());
        $this->assertSame(1, CommunicationLog::query()
            ->where('correlation_id', 'welcome_'.$user->getKey())
            ->where('channel', 'email')
            ->count());
    }

    public function test_linking_google_does_not_re_welcome_an_already_verified_account(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'google_id' => null,
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        // Their welcome was already sent when they verified, months ago.
        event(new Verified($user));
        $before = CommunicationLog::query()->where('correlation_id', 'welcome_'.$user->getKey())->count();

        app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertSame($before, CommunicationLog::query()
            ->where('correlation_id', 'welcome_'.$user->getKey())
            ->count());
    }

    public function test_a_pre_existing_google_account_created_before_this_step_is_never_held(): void
    {
        // The ~400 accounts already on the platform: backfilled as complete by
        // the migration even though Google never gave us their details, so the
        // requirement starts with new signups only.
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'google_id' => 'google-123',
            'country' => null,
            'phone' => null,
            'gender' => null,
            'profile_completed_at' => now(),
        ]);

        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('jane@example.com'));

        $this->assertFalse($result['is_new']);
        $this->assertFalse($user->fresh()->needsProfileCompletion());

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_only_a_brand_new_google_account_owes_the_completion_step(): void
    {
        // Scenario 3, the one case the hold exists for.
        $result = app(GoogleAuthService::class)->findOrCreateUser($this->googleUser('newcomer@example.com'));

        $this->assertTrue($result['is_new']);
        $this->assertTrue($result['user']->needsProfileCompletion());
    }

    public function test_a_password_signup_is_complete_on_arrival_and_never_sees_the_step(): void
    {
        $component = Volt::test('auth.auth-modal');

        foreach ([
            'regName' => 'Ada Lovelace',
            'regEmail' => 'ada@example.com',
            'regCountry' => 'NG',
            'regPhone' => '8031234567',
            'regPassword' => 'Lagos-rain-at-7!',
            'regPasswordConfirmation' => 'Lagos-rain-at-7!',
            'regGender' => 'female',
            'regAcceptedTerms' => true,
        ] as $field => $value) {
            $component->set($field, $value);
        }

        $component->call('register')->assertHasNoErrors();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertNotNull($user->profile_completed_at);
        $this->assertFalse($user->needsProfileCompletion());
    }

    public function test_signing_in_with_a_password_does_not_trigger_the_step(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        Volt::test('auth.auth-modal')
            ->set('loginEmail', 'ada@example.com')
            ->set('loginPassword', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_guest_is_not_affected_by_the_hold(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_finishing_returns_the_customer_to_where_they_were_headed(): void
    {
        $user = User::factory()->profileIncomplete()->create();

        $this->actingAs($user);

        // The guard stores the blocked destination before redirecting.
        $this->get('/checkout')->assertRedirect(route('auth.complete-profile'));

        Volt::test('auth.complete-profile')
            ->set('country', 'NG')
            ->set('phone', '08031234567')
            ->set('gender', 'male')
            ->set('acceptedTerms', true)
            ->call('save')
            ->assertRedirect('/checkout');
    }
}
