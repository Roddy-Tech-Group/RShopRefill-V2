<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\WalletService;
use App\Models\CurrencyRate;
use App\Models\Notification;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wallet balances, statements, and the notification tray.
 *
 * Read-heavy, so the tests are mostly about scoping and about not converting things
 * that should not be converted. A naira wallet holds naira: showing it as dollars
 * would suggest it can be spent as dollars, and the figure would drift with the
 * rate while the real balance sat still.
 */
class WalletAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = app(ApiTokenService::class)->issuePair($this->user, 'install-phone-1')->accessToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function api(string $method, string $path, array $body = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $path, $body, array_merge(
            ['Authorization' => 'Bearer '.$this->token],
            $headers,
        ));
    }

    private function notification(array $attributes = [], ?User $owner = null): Notification
    {
        return Notification::create(array_merge([
            'user_id' => ($owner ?? $this->user)->id,
            'type' => 'order_update',
            'category' => 'order',
            'title' => 'Your order is on its way',
            'message' => 'RSR-1 has been delivered.',
            'channel' => 'in_app',
            'status' => 'sent',
            'priority' => 'normal',
            'sent_at' => now(),
        ], $attributes));
    }

    // ------------------------------------------------------------------ wallets

    public function test_wallets_need_a_signed_in_customer(): void
    {
        $this->getJson('/v2/wallets')->assertUnauthorized();
    }

    public function test_a_wallet_reports_its_total_locked_and_spendable_balances(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);
        $wallet->forceFill(['balance' => 50000, 'locked_balance' => 2000])->save();

        $row = $this->api('GET', '/v2/wallets/NGN')->assertOk()->json('data');

        $this->assertSame('NGN', $row['currency']);
        $this->assertSame('50000.00', $row['balance']['amount']);
        $this->assertSame('2000.00', $row['locked_balance']['amount']);
        // What a checkout screen actually cares about.
        $this->assertSame('48000.00', $row['available_balance']['amount']);
    }

    /**
     * A naira wallet holds naira, whatever currency the app happens to be showing
     * prices in. Converting it would misdescribe what the customer can spend.
     */
    public function test_a_balance_is_never_converted_into_the_display_currency(): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1600, 'is_active' => true, 'sort_order' => 1],
        );
        CurrencyRate::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1, 'is_active' => true, 'sort_order' => 0],
        );

        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);
        $wallet->forceFill(['balance' => 16000])->save();

        $row = $this->api('GET', '/v2/wallets/NGN', [], ['X-Display-Currency' => 'USD'])
            ->assertOk()
            ->json('data');

        $this->assertSame('NGN', $row['balance']['currency']);
        $this->assertSame('16000.00', $row['balance']['amount']);
    }

    public function test_an_unsupported_currency_is_refused_rather_than_invented(): void
    {
        $this->api('GET', '/v2/wallets/XYZ')
            ->assertNotFound()
            ->assertJsonPath('error.type', 'unsupported_currency');
    }

    public function test_a_statement_lists_only_the_callers_own_movements(): void
    {
        $mine = app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);

        $intruder = User::factory()->create();
        $theirs = app(WalletService::class)->getOrCreateWallet($intruder, Currency::NGN);

        WalletTransaction::create([
            'wallet_id' => $mine->id, 'user_id' => $this->user->id, 'type' => 'credit',
            'amount' => 5000, 'currency' => 'NGN', 'balance_before' => 0, 'balance_after' => 5000,
            'description' => 'Wallet funded', 'reference' => 'REF-MINE',
        ]);

        WalletTransaction::create([
            'wallet_id' => $theirs->id, 'user_id' => $intruder->id, 'type' => 'credit',
            'amount' => 9999, 'currency' => 'NGN', 'balance_before' => 0, 'balance_after' => 9999,
            'description' => 'Wallet funded', 'reference' => 'REF-THEIRS',
        ]);

        $references = array_column(
            $this->api('GET', '/v2/wallets/transactions')->assertOk()->json('data'),
            'reference',
        );

        $this->assertSame(['REF-MINE'], $references);
    }

    /**
     * A statement people can reconcile is worth far more than one they have to add
     * up, and balance_after is what lets a customer point at the exact line where a
     * figure stopped matching.
     */
    public function test_a_statement_line_carries_the_running_balance(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'user_id' => $this->user->id, 'type' => 'debit',
            'amount' => 1500, 'currency' => 'NGN', 'balance_before' => 5000, 'balance_after' => 3500,
            'transaction_category' => 'purchase', 'description' => 'MTN airtime', 'reference' => 'REF-1',
        ]);

        $line = $this->api('GET', '/v2/wallets/transactions')->assertOk()->json('data.0');

        $this->assertSame('debit', $line['type']);
        $this->assertSame('1500.00', $line['amount']['amount']);
        $this->assertSame('3500.00', $line['balance_after']['amount']);
        $this->assertSame('MTN airtime', $line['description']);
    }

    /**
     * Internal plumbing. source_id would expose row ids from tables the app has no
     * business knowing about, and the idempotency key is ours.
     */
    public function test_internal_bookkeeping_is_not_published_on_a_statement(): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);

        WalletTransaction::create([
            'wallet_id' => $wallet->id, 'user_id' => $this->user->id, 'type' => 'credit',
            'amount' => 100, 'currency' => 'NGN', 'balance_before' => 0, 'balance_after' => 100,
            'reference' => 'REF-1', 'idempotency_key' => 'internal-key-should-not-show',
            'source_type' => 'App\\Models\\WalletFunding', 'source_id' => 4242,
        ]);

        $body = $this->api('GET', '/v2/wallets/transactions')->assertOk()->getContent();

        $this->assertStringNotContainsString('internal-key-should-not-show', $body);
        $this->assertStringNotContainsString('source_id', $body);
        $this->assertStringNotContainsString('4242', $body);
        $this->assertStringNotContainsString('WalletFunding', $body);
    }

    // ------------------------------------------------------------ notifications

    public function test_the_tray_lists_only_the_callers_own_notifications(): void
    {
        $this->notification(['title' => 'Mine']);
        $this->notification(['title' => 'Theirs'], User::factory()->create());

        $titles = array_column($this->api('GET', '/v2/notifications')->assertOk()->json('data'), 'title');

        $this->assertSame(['Mine'], $titles);
    }

    public function test_the_unread_badge_counts_only_unread(): void
    {
        $this->notification();
        $this->notification(['read_at' => now()]);

        $this->assertSame(1, $this->api('GET', '/v2/notifications/unread-count')->assertOk()->json('data.unread'));
    }

    public function test_the_tray_can_be_filtered_to_unread(): void
    {
        $this->notification(['title' => 'New']);
        $this->notification(['title' => 'Old', 'read_at' => now()]);

        $titles = array_column(
            $this->api('GET', '/v2/notifications?unread=1')->assertOk()->json('data'),
            'title',
        );

        $this->assertSame(['New'], $titles);
    }

    /**
     * Re-reading must not rewrite the timestamp, or a second tap would reorder the
     * customer's tray under their finger.
     */
    public function test_marking_read_twice_does_not_move_the_timestamp(): void
    {
        $notification = $this->notification();

        $first = $this->api('PATCH', '/v2/notifications/'.$notification->id.'/read')->assertOk();
        $readAt = $first->json('data.read_at');

        $this->travel(5)->minutes();

        $second = $this->api('PATCH', '/v2/notifications/'.$notification->id.'/read')->assertOk();

        $this->assertTrue($second->json('data.read'));
        $this->assertSame($readAt, $second->json('data.read_at'));
    }

    public function test_another_customers_notification_cannot_be_marked_read(): void
    {
        $theirs = $this->notification([], User::factory()->create());

        $this->api('PATCH', '/v2/notifications/'.$theirs->id.'/read')
            ->assertNotFound()
            ->assertJsonPath('error.type', 'notification_not_found');

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_everything_can_be_marked_read_at_once(): void
    {
        $this->notification();
        $this->notification();
        $this->notification(['read_at' => now()]);

        $this->assertSame(2, $this->api('PATCH', '/v2/notifications/read-all')->assertOk()->json('data.marked_read'));
        $this->assertSame(0, $this->api('GET', '/v2/notifications/unread-count')->json('data.unread'));
    }

    /**
     * One toggle at a time. Sending the whole set would mean the app could reset a
     * preference it did not mean to touch just by omitting it.
     */
    public function test_a_single_preference_can_be_changed_without_resetting_the_others(): void
    {
        $this->api('PUT', '/v2/notifications/preferences', [
            'categories' => [
                'marketing' => ['email' => false, 'push' => false],
                'orders' => ['email' => true],
            ],
        ])->assertOk();

        // Only marketing email is sent this time. Everything else must survive.
        $after = $this->api('PUT', '/v2/notifications/preferences', [
            'categories' => ['marketing' => ['email' => true]],
        ])->assertOk()->json('data');

        $this->assertTrue($after['categories']['marketing']['email']);
        $this->assertFalse($after['categories']['marketing']['push'], 'an omitted switch must be left alone');
        $this->assertTrue($after['categories']['orders']['email']);
    }

    public function test_an_update_with_nothing_in_it_is_refused(): void
    {
        $this->api('PUT', '/v2/notifications/preferences', [])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'no_preferences_given');
    }

    /**
     * There is no switch for security notices, and there should not be: the person
     * most likely to want "somebody signed in from a new device" silenced is
     * whoever stole the account.
     */
    public function test_security_notices_cannot_be_switched_off(): void
    {
        $categories = $this->api('GET', '/v2/notifications/preferences')
            ->assertOk()
            ->json('data.categories');

        $this->assertArrayNotHasKey('security', $categories);
    }

    public function test_preferences_can_be_read_back(): void
    {
        $this->api('GET', '/v2/notifications/preferences')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'channels' => ['email', 'push'],
                'categories' => [
                    'wallet' => ['email', 'push'],
                    'orders' => ['email', 'push'],
                    'transactions' => ['email', 'push'],
                    'product_updates' => ['email', 'push'],
                    'marketing' => ['email', 'push'],
                ],
            ]]);
    }

    // ------------------------------------------------------------ contract

    /**
     * Rcoin is a points balance in the wallets table. Marked, so the app never adds
     * it into a cash total.
     */
    public function test_the_points_wallet_is_marked_as_points(): void
    {
        app(WalletService::class)->getOrCreateWallet($this->user, Currency::RCOIN);
        app(WalletService::class)->getOrCreateWallet($this->user, Currency::NGN);

        $wallets = collect($this->api('GET', '/v2/wallets')->assertOk()->json('data'))->keyBy('currency');

        $this->assertSame('points', $wallets['RCOIN']['kind']);
        $this->assertSame('cash', $wallets['NGN']['kind']);
    }

    /**
     * notifications.category exists as a column but nothing ever writes it, so a
     * field for it would be null on every real row. It is not part of the contract.
     */
    public function test_the_tray_does_not_publish_the_unused_category_column(): void
    {
        $this->notification();

        $this->assertArrayNotHasKey('category', $this->api('GET', '/v2/notifications')->assertOk()->json('data.0'));
    }

    /**
     * Only channels the pipeline honours are offered. An SMS switch that nothing
     * reads would be a promise the app shows and we do not keep.
     */
    public function test_only_honoured_channels_are_offered(): void
    {
        $channels = $this->api('GET', '/v2/notifications/preferences')->assertOk()->json('data.channels');

        $this->assertSame(['email', 'push'], array_keys($channels));
    }
}
