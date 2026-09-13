<?php

namespace Tests\Feature\Compliance;

use App\Domain\Auth\Exceptions\AccountClosureException;
use App\Domain\Auth\Services\AccountClosureService;
use App\Domain\Communication\Mail\Security\AccountClosedMail;
use App\Domain\Communication\Mail\Security\AccountClosureRequestedMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Domain\Shared\Enums\Currency;
use App\Models\AccountClosureFeedback;
use App\Models\ClosedAccountIdentity;
use App\Models\CurrencyRate;
use App\Models\KycVerification;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use LogicException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The privacy policy promises a customer can close their account, and that we
 * keep transaction and identity records for the years financial law requires.
 * Closure therefore anonymises the user row after a grace period instead of
 * deleting it, and nothing financial is ever destroyed on the way.
 */
class AccountClosureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<int, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['retention.account_closure_grace_days' => 30]);

        // CommunicationManager swallows failures by design, so record what was
        // handed to it instead of watching the mailer.
        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args): void {
                $this->sent[] = $args;
            });
        });
    }

    private function emptyWallet(User $user, float $balance = 0): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'currency' => Currency::USD,
            'balance' => $balance,
        ]);
    }

    public function test_closing_marks_the_account_signs_out_and_explains_the_grace_period(): void
    {
        $user = User::factory()->create();
        $this->emptyWallet($user);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();
        $this->assertNotNull($user->closure_requested_at);
        $this->assertNull($user->anonymised_at);
        $this->assertTrue($user->hasRequestedClosure());
        $this->assertSame(now()->addDays(30)->toDateString(), $user->closesOn()->toDateString());
        $this->assertFalse(auth()->check());

        // Nothing was removed: the row and its email are intact for the grace period.
        $this->assertNotNull(User::find($user->id));
        $this->assertSame($user->email, User::find($user->id)->email);

        $this->assertCount(1, $this->sent);
        $this->assertInstanceOf(AccountClosureRequestedMail::class, $this->sent[0][4]);
        $this->assertSame(now()->addDays(30)->toDateString(), $this->sent[0][4]->closesOn->toDateString());
    }

    public function test_optional_exit_feedback_is_kept_by_user_id_only(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('reasons', ['prices_fees', 'not_a_real_reason', 'other'])
            ->set('comment', '  Too expensive for airtime.  ')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasErrors(['reasons.1']);

        Volt::test('settings.delete-user-form')
            ->set('reasons', ['prices_fees', 'other'])
            ->set('comment', '  Too expensive for airtime.  ')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasNoErrors();

        $feedback = AccountClosureFeedback::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(['prices_fees', 'other'], $feedback->reasons);
        $this->assertSame('Too expensive for airtime.', $feedback->comment);

        // Nothing written when the customer says nothing.
        $silent = User::factory()->create();
        app(AccountClosureService::class)->request($silent);
        $this->assertDatabaseMissing('account_closure_feedback', ['user_id' => $silent->id]);
    }

    public function test_the_wrong_password_closes_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('closeAccount')
            ->assertHasErrors(['password']);

        $this->assertNull($user->fresh()->closure_requested_at);
        $this->assertTrue(auth()->check());
        $this->assertSame([], $this->sent);
    }

    public function test_a_google_only_account_confirms_by_typing_its_email(): void
    {
        $user = User::factory()->create(['password' => null, 'google_id' => 'g-123', 'email' => 'Gina@Example.test']);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->set('confirmation', 'someone-else@example.test')
            ->call('closeAccount')
            ->assertHasErrors(['confirmation']);

        $this->assertNull($user->fresh()->closure_requested_at);

        Volt::test('settings.delete-user-form')
            ->set('confirmation', ' gina@example.test ')
            ->call('closeAccount')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertNotNull($user->fresh()->closure_requested_at);
    }

    public function test_money_still_in_the_wallet_blocks_closure(): void
    {
        $user = User::factory()->create();
        $this->emptyWallet($user, 12.5);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->assertSee('still holds 12.50 USD')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasErrors(['closure']);

        $this->assertNull($user->fresh()->closure_requested_at);
        $this->assertTrue(auth()->check());
    }

    public function test_a_dust_balance_is_forfeited_rather_than_blocking_closure(): void
    {
        $user = User::factory()->create();
        $this->emptyWallet($user, 0.4);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->assertSee('remaining balance of 0.40 USD')
            ->assertDontSee('still holds')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasNoErrors();

        $this->assertNotNull($user->fresh()->closure_requested_at);
    }

    public function test_dust_is_judged_in_usd_terms_for_any_wallet_currency(): void
    {
        CurrencyRate::updateOrCreate(['code' => 'XAF'], [
            'name' => 'CFA Franc', 'type' => 'fiat', 'rate_per_usd' => 600.0, 'is_active' => true,
        ]);
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => Currency::XAF, 'balance' => 300]);
        $this->actingAs($user);

        // 300 XAF is half a dollar: forfeited, not blocking.
        $this->assertSame([], app(AccountClosureService::class)->blockers($user));
        $this->assertSame(['300.00 XAF'], app(AccountClosureService::class)->forfeitedBalances($user));

        // 3,000 XAF is five dollars: blocks.
        $user->wallets()->first()->update(['balance' => 3000]);
        $this->assertCount(1, app(AccountClosureService::class)->blockers($user));
    }

    public function test_an_order_still_in_flight_blocks_closure(): void
    {
        $user = User::factory()->create();
        Order::factory()->create(['user_id' => $user->id, 'order_status' => 'processing']);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')
            ->assertSee('still being processed')
            ->set('password', 'password')
            ->call('closeAccount')
            ->assertHasErrors(['closure']);

        $this->assertNull($user->fresh()->closure_requested_at);
    }

    public function test_signing_in_during_the_grace_period_cancels_the_closure(): void
    {
        $user = User::factory()->create(['closure_requested_at' => now()->subDays(10)]);

        // Every sign-in path fires the Login event that the listener watches.
        Auth::login($user);

        $this->assertNull($user->fresh()->closure_requested_at);
        $this->assertFalse($user->fresh()->hasRequestedClosure());
        $this->assertSame('Your account stays open', $this->sent[0][2]);
        $this->assertNotNull(session('account_closure_cancelled'));
    }

    public function test_the_grace_period_ends_in_anonymisation_not_deletion(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.test',
            'name' => 'Ada Okafor',
            'phone' => '+2348012345678',
            'google_id' => 'g-ada',
            'closure_requested_at' => now()->subDays(31),
        ]);
        $wallet = $this->emptyWallet($user);
        $order = Order::factory()->create(['user_id' => $user->id, 'order_status' => 'completed']);
        DB::table('trusted_devices')->insert([
            'user_id' => $user->id,
            'device_uuid' => 'dev-1',
            'fingerprint' => 'fp-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stillInGrace = User::factory()->create(['closure_requested_at' => now()->subDays(5)]);

        $this->artisan('retention:close-accounts')
            ->expectsOutputToContain('Closed 1, failed 0')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertTrue($user->isClosed());
        $this->assertSame('Closed account', $user->name);
        $this->assertSame("closed-{$user->id}@anonymised.invalid", $user->email);
        $this->assertNull($user->phone);
        $this->assertNull($user->google_id);
        $this->assertNull($user->password);
        $this->assertNull($user->remember_token);

        // The financial rows survive, still pointing at the same pseudonymous id.
        $this->assertSame($user->id, $wallet->fresh()->user_id);
        $this->assertSame($user->id, $order->fresh()->user_id);

        // The ways back in are gone.
        $this->assertDatabaseMissing('trusted_devices', ['user_id' => $user->id]);
        $this->assertFalse(Auth::attempt(['email' => 'ada@example.test', 'password' => 'password']));
        $this->assertNull(User::where('google_id', 'g-ada')->first());

        // The other account is untouched until its own deadline.
        $this->assertFalse($stillInGrace->fresh()->isClosed());

        // The farewell went to the address the customer actually had, by name.
        $farewell = collect($this->sent)->first(fn (array $args) => ($args[4] ?? null) instanceof AccountClosedMail);
        $this->assertNotNull($farewell);
        $this->assertSame('Ada Okafor', $farewell[4]->name);
        $this->assertSame('ada@example.test', $farewell[9]);
    }

    public function test_an_account_that_moved_money_keeps_its_identity_in_the_compliance_drawer(): void
    {
        $user = User::factory()->create([
            'email' => 'ada@example.test', 'name' => 'Ada Okafor', 'phone' => '+2348012345678', 'country' => 'NG',
            'closure_requested_at' => now()->subDays(31),
        ]);
        Order::factory()->create(['user_id' => $user->id, 'order_status' => 'completed']);
        KycVerification::create(['user_id' => $user->id, 'provider' => 'smile', 'reference' => 'SMILE-REF-1', 'status' => 'approved']);

        $this->artisan('retention:close-accounts')->assertExitCode(0);

        $identity = ClosedAccountIdentity::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Ada Okafor', $identity->name);
        $this->assertSame('ada@example.test', $identity->email);
        $this->assertSame('+2348012345678', $identity->phone);
        $this->assertSame('NG', $identity->country);
        $this->assertSame('SMILE-REF-1', $identity->kyc_reference);
        $this->assertSame(now()->addYears(5)->toDateString(), $identity->retain_until->toDateString());

        // Encrypted at rest: the raw column is not the plain email.
        $raw = DB::table('closed_account_identities')->where('user_id', $user->id)->value('email');
        $this->assertNotSame('ada@example.test', $raw);

        // The public profile is still anonymised as promised.
        $this->assertSame('Closed account', $user->fresh()->name);
    }

    public function test_an_account_that_never_moved_money_leaves_no_identity_behind(): void
    {
        $user = User::factory()->create(['closure_requested_at' => now()->subDays(31)]);

        $this->artisan('retention:close-accounts')->assertExitCode(0);

        $this->assertTrue($user->fresh()->isClosed());
        $this->assertDatabaseMissing('closed_account_identities', ['user_id' => $user->id]);
    }

    public function test_a_closed_customer_can_register_again_with_the_same_details(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test', 'phone' => '+2348012345678', 'google_id' => 'g-ada', 'closure_requested_at' => now()->subDays(31)]);
        $this->artisan('retention:close-accounts')->assertExitCode(0);

        $again = User::factory()->create(['email' => 'ada@example.test', 'phone' => '+2348012345678', 'google_id' => 'g-ada']);

        $this->assertNotSame($user->id, $again->id);
        $this->assertFalse($again->isClosed());
    }

    public function test_the_closure_sweep_is_idempotent_and_has_a_dry_run(): void
    {
        $user = User::factory()->create(['closure_requested_at' => now()->subDays(40)]);

        $this->artisan('retention:close-accounts', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);
        $this->assertFalse($user->fresh()->isClosed());

        $this->artisan('retention:close-accounts')->assertExitCode(0);
        $anonymisedAt = $user->fresh()->anonymised_at;

        $this->artisan('retention:close-accounts')
            ->expectsOutputToContain('No account closures are due')
            ->assertExitCode(0);
        $this->assertEquals($anonymisedAt, $user->fresh()->anonymised_at);
    }

    public function test_a_closed_account_cannot_be_closed_again_or_hard_deleted(): void
    {
        $user = User::factory()->create(['anonymised_at' => now()]);

        try {
            app(AccountClosureService::class)->request($user);
            $this->fail('A closed account should not accept a new closure request.');
        } catch (AccountClosureException) {
        }

        $this->expectException(LogicException::class);
        $user->delete();
    }

    public function test_a_live_session_on_a_closed_account_is_signed_out(): void
    {
        $user = User::factory()->create(['anonymised_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        $this->assertFalse(auth()->check());
    }
}
