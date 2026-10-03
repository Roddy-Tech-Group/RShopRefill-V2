<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Cart\Services\CartManager;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteSetting;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Buying something, and paying for it.
 *
 * The two tests that justify this whole phase are the retry ones. A customer taps Pay
 * on mobile data, we create the order and take the money, and the reply is lost in a
 * tunnel. The app retries with the only information it has. Without the idempotency
 * guard we create a second order and charge a second time, and somebody is owed a
 * refund and an apology.
 *
 * Everything else here is about the app not getting a looser path to money than a
 * browser has. Placing an order runs CheckoutService, the same service the website
 * charges through, so the gateway kill switch, the risk engine, the spending caps and
 * the PIN requirement all still apply. Several tests exist purely to prove that a
 * refusal raised deep inside that pipeline reaches the app as something it can act on
 * rather than as a generic failure.
 */
class CheckoutAndPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLATION = 'install-phone-1';

    private const KEY = 'tap-aaaa-bbbb-cccc';

    private User $user;

    private string $token;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        CurrencyRate::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'type' => 'fiat', 'rate_per_usd' => 1, 'is_active' => true, 'sort_order' => 0],
        );
        CurrencyRate::updateOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1600, 'is_active' => true, 'sort_order' => 1],
        );

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'country' => 'NG',
        ]);

        $this->token = app(ApiTokenService::class)
            ->issuePair($this->user, self::INSTALLATION)
            ->accessToken;

        $category = Category::factory()->create(['is_active' => true, 'slug' => 'mobile-airtime']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        $product = Product::factory()->create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'subcategory_id' => $subcategory->id,
            'is_available' => true,
            'currency' => 'USD',
            'face_value' => 5,
            'cost_price' => 1,
            'manual_retail_price_usd' => 5.0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function api(string $method, string $path, array $body = [], ?string $key = self::KEY): TestResponse
    {
        $this->app['auth']->forgetGuards();

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'X-Installation-ID' => self::INSTALLATION,
        ];

        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->json($method, $path, $body, $headers);
    }

    /** A basket with one item in it, ready to be paid for. */
    private function cart(): string
    {
        $cart = app(CartManager::class)->resolveCart($this->user->id);

        app(CartManager::class)->addItem($cart, $this->variant, 1);

        return (string) $cart->id;
    }

    private function givePin(string $pin = '8317'): void
    {
        app(TransactionPinService::class)->setupPin($this->user, $pin);
    }

    private function fundWallet(float $amount = 100.0): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($this->user, Currency::USD);
        $wallet->forceFill(['balance' => $amount])->save();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function placeOrder(array $overrides = [], ?string $key = self::KEY): TestResponse
    {
        $body = array_merge([
            'payment_method' => 'wallet',
            'currency' => 'USD',
        ], $overrides);

        // Built lazily. Putting $this->cart() in the defaults would add an item to
        // the customer's active cart even when a test supplied its own id, which is
        // how the empty-basket test ended up with something in it.
        $body['cart_id'] ??= $this->cart();

        return $this->api('POST', '/v2/checkout/orders', $body, $key);
    }

    // ------------------------------------------------------------------ the tap

    /**
     * The happy path for a wallet payment. No money moves yet: the order exists, and
     * the session is waiting for the PIN that authorises the spend.
     */
    public function test_a_cart_becomes_an_order_awaiting_its_pin(): void
    {
        $this->givePin();
        $this->fundWallet();

        $response = $this->placeOrder()->assertCreated();

        $response->assertJsonStructure(['data' => [
            'order' => ['order_number', 'total', 'order_status', 'items'],
            'payment' => ['id', 'status', 'next_action'],
        ]]);

        // The app is told what to DO, rather than having to infer it from a provider
        // name it would need a new release to learn about.
        $this->assertSame('confirm_with_pin', $response->json('data.payment.next_action.type'));

        $this->assertSame(1, Order::count());
    }

    /**
     * THE one. Same tap, sent twice, charged once.
     */
    public function test_a_retried_tap_returns_the_same_order_and_does_not_create_a_second(): void
    {
        $this->givePin();
        $this->fundWallet();

        $cartId = $this->cart();

        $first = $this->api('POST', '/v2/checkout/orders', [
            'cart_id' => $cartId, 'payment_method' => 'wallet', 'currency' => 'USD',
        ])->assertCreated();

        $retry = $this->api('POST', '/v2/checkout/orders', [
            'cart_id' => $cartId, 'payment_method' => 'wallet', 'currency' => 'USD',
        ])->assertCreated();

        $this->assertSame(
            $first->json('data.order.order_number'),
            $retry->json('data.order.order_number'),
        );

        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));

        // The bit that matters.
        $this->assertSame(1, Order::count());
    }

    public function test_placing_an_order_without_an_idempotency_key_is_refused(): void
    {
        $this->givePin();
        $this->fundWallet();

        $this->placeOrder([], null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_required');

        $this->assertSame(0, Order::count());
    }

    public function test_another_customers_basket_cannot_be_paid_for(): void
    {
        $intruder = User::factory()->create();
        $theirCart = app(CartManager::class)->resolveCart($intruder->id);
        app(CartManager::class)->addItem($theirCart, $this->variant, 1);

        $this->placeOrder(['cart_id' => (string) $theirCart->id])
            ->assertNotFound()
            ->assertJsonPath('error.type', 'cart_not_found');

        $this->assertSame(0, Order::count());
    }

    public function test_an_empty_basket_is_refused(): void
    {
        $cart = app(CartManager::class)->resolveCart($this->user->id);

        $this->placeOrder(['cart_id' => (string) $cart->id])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'cart_empty');
    }

    /**
     * The admin kill switch, answered before anything else. "Checkout is off" must not
     * depend on having a valid basket.
     */
    public function test_the_checkout_kill_switch_stops_the_app_too(): void
    {
        // FeatureFlag reads SiteSetting, and the key convention is
        // features.{name}_enabled.
        SiteSetting::updateOrCreate(['key' => 'features.checkout_enabled'], [
            'group' => 'features', 'value' => 'off',
        ]);

        $this->placeOrder()
            ->assertStatus(503)
            ->assertJsonPath('error.type', 'checkout_unavailable');

        $this->assertSame(0, Order::count());
    }

    // ------------------------------------------------- refusals the app can act on

    /**
     * Paying from a wallet with no PIN set. The app needs to know to send them to set
     * one, which it cannot work out from a generic failure.
     */
    public function test_a_wallet_payment_with_no_pin_says_so_precisely(): void
    {
        $this->fundWallet();

        $this->placeOrder()
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'transaction_pin_required');

        // The order IS created before the gateway is initialised, so a refusal at this
        // stage leaves a row behind. CheckoutService marks it FAILED rather than
        // leaving it dangling as pending, which is the better of the two behaviours:
        // nothing was charged, nothing is waiting to be paid, and the customer's
        // history reads honestly.
        $this->assertSame('failed', Order::sole()->order_status->value);
    }

    /**
     * Not enough in the wallet. A distinct type, so the app can offer to top up rather
     * than just saying no.
     */
    public function test_an_empty_wallet_says_so_precisely(): void
    {
        $this->givePin();

        $this->placeOrder()
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'insufficient_balance');
    }

    public function test_an_unverified_email_cannot_buy(): void
    {
        $this->user->forceFill(['email_verified_at' => null])->save();
        $this->givePin();
        $this->fundWallet();

        $response = $this->placeOrder();

        $this->assertContains($response->status(), [403, 409]);
        $this->assertIsString($response->json('error.type'));
        $this->assertSame(0, Order::count());
    }

    // ----------------------------------------------------------------- payments

    public function test_a_payment_session_can_be_read_by_its_owner(): void
    {
        $this->givePin();
        $this->fundWallet();

        $sessionId = $this->placeOrder()->json('data.payment.id');

        $this->api('GET', '/v2/payments/'.$sessionId, [], null)
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonStructure(['data' => ['status', 'amount', 'next_action', 'order_number']]);
    }

    /**
     * A session belonging to somebody else answers exactly like one that never existed,
     * so the endpoint cannot be used to confirm that an id is real.
     */
    public function test_another_customers_payment_answers_like_a_missing_one(): void
    {
        $this->givePin();
        $this->fundWallet();

        $sessionId = $this->placeOrder()->json('data.payment.id');

        $intruder = User::factory()->create();
        $this->token = app(ApiTokenService::class)->issuePair($intruder, 'install-phone-2')->accessToken;

        $theirs = $this->api('GET', '/v2/payments/'.$sessionId, [], null)->assertNotFound();
        $missing = $this->api('GET', '/v2/payments/00000000-0000-0000-0000-000000000000', [], null)
            ->assertNotFound();

        $this->assertSame($theirs->json(), $missing->json());
        $this->assertSame('payment_not_found', $theirs->json('error.type'));
    }

    /**
     * Verification has to be callable as often as the app likes: recovering from a
     * missed deep link is precisely to keep asking. So no idempotency key, and no
     * refusal for asking twice.
     */
    public function test_verification_can_be_called_repeatedly_without_a_key(): void
    {
        $this->givePin();
        $this->fundWallet();

        $sessionId = $this->placeOrder()->json('data.payment.id');

        foreach (range(1, 3) as $ignored) {
            $response = $this->api('POST', '/v2/payments/'.$sessionId.'/verify', [], null);

            // Whatever the verdict, it must not be "you already asked".
            $this->assertNotSame(409, $response->status());
            $this->assertNotSame(400, $response->status());
        }
    }

    public function test_authorising_a_payment_requires_an_idempotency_key(): void
    {
        $this->givePin();
        $this->fundWallet();

        $sessionId = $this->placeOrder()->json('data.payment.id');

        $this->api('POST', '/v2/payments/'.$sessionId.'/pay', ['method' => 'wallet'], null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_required');
    }

    // ----------------------------------------------------------- wallet funding

    public function test_a_wallet_can_be_funded(): void
    {
        $response = $this->api('POST', '/v2/wallets/fund', [
            'currency' => 'NGN',
            'amount' => 5000,
        ])->assertCreated();

        $response->assertJsonStructure(['data' => ['reference', 'amount', 'payment' => ['id', 'status']]]);

        $this->assertSame('5000.00', $response->json('data.amount.amount'));
        $this->assertSame('NGN', $response->json('data.amount.currency'));
        $this->assertSame(1, WalletFunding::count());
    }

    /**
     * The other one that justifies the phase. The storefront's equivalent has no
     * duplicate protection at all: two taps make two fundings and two payment
     * sessions, and a customer who pays both has funded twice.
     */
    public function test_a_retried_funding_does_not_create_a_second_one(): void
    {
        $first = $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 5000])
            ->assertCreated();

        $retry = $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 5000])
            ->assertCreated();

        $this->assertSame($first->json('data.reference'), $retry->json('data.reference'));
        $this->assertSame('true', $retry->headers->get('Idempotent-Replay'));
        $this->assertSame(1, WalletFunding::count());
    }

    public function test_funding_a_wallet_without_an_idempotency_key_is_refused(): void
    {
        $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 5000], null)
            ->assertStatus(400)
            ->assertJsonPath('error.type', 'idempotency_key_required');

        $this->assertSame(0, WalletFunding::count());
    }

    /**
     * Two DIFFERENT top-ups are two genuine intents. The guard must not over-collapse
     * somebody funding twice on purpose.
     */
    public function test_two_deliberate_fundings_both_go_through(): void
    {
        $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 5000], 'tap-first-one-111')
            ->assertCreated();

        $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 9000], 'tap-second-one-22')
            ->assertCreated();

        $this->assertSame(2, WalletFunding::count());
    }

    // ------------------------------------------------------------ transaction PIN

    public function test_a_pin_can_be_set_and_then_verified_for_a_short_lived_token(): void
    {
        $this->assertFalse($this->api('GET', '/v2/transaction-pin', [], null)->assertOk()->json('data.has_pin'));

        $this->api('POST', '/v2/transaction-pin', ['pin' => '8317', 'pin_confirmation' => '8317'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pin_set');

        $this->assertTrue($this->api('GET', '/v2/transaction-pin', [], null)->assertOk()->json('data.has_pin'));

        $verified = $this->api('POST', '/v2/transaction-pin/verify', ['pin' => '8317'], null)->assertOk();

        $this->assertIsString($verified->json('data.auth_token'));
        $this->assertSame(TransactionPinService::AUTH_TOKEN_TTL_MINUTES * 60, $verified->json('data.expires_in'));
    }

    // ------------------------------------------------- currencies and rails

    /**
     * The rate lookup behind checkout falls back to the dollar row for a code it
     * does not know, so an unknown currency would price a $5 cart as "5.00 ZAR" and
     * charge that. Three letters is not a currency; a live rate row is.
     */
    public function test_a_currency_without_a_live_rate_cannot_be_charged_in(): void
    {
        $this->givePin();
        $this->fundWallet();

        $this->placeOrder(['currency' => 'ZAR'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['currency']]]);

        $this->assertSame(0, Order::count());
    }

    public function test_points_cannot_be_the_wallet_an_order_is_paid_from(): void
    {
        $this->placeOrder(['wallet_currency' => 'RCOIN'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['wallet_currency']]]);
    }

    public function test_a_rail_the_currency_does_not_accept_is_refused_before_an_order_exists(): void
    {
        CurrencyRate::updateOrCreate(
            ['code' => 'ZAR'],
            ['name' => 'South African Rand', 'type' => 'fiat', 'rate_per_usd' => 18, 'is_active' => true, 'sort_order' => 9],
        );

        // ZAR has no explicit rail list, so only a card can take it. Crypto cannot.
        $this->placeOrder(['currency' => 'ZAR', 'payment_method' => 'crypto'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['payment_method']]]);

        $this->assertSame(0, Order::count());
    }

    /**
     * A payment raised in naira reports its settlement in dollars at the rate it was
     * raised at. The naira figure labelled as dollars is what it used to say.
     */
    public function test_a_payment_in_another_currency_reports_its_settlement_in_dollars(): void
    {
        $this->givePin();
        $this->fundWallet();

        $payment = $this->placeOrder(['currency' => 'NGN'])->assertCreated()->json('data.payment');

        $this->assertSame('NGN', $payment['amount']['currency']);
        $this->assertSame('8000.00', $payment['amount']['amount']);
        $this->assertSame('5.0000', $payment['amount']['usd']);
    }

    // ------------------------------------------------------- wallet funding

    public function test_funding_below_the_wallets_minimum_is_refused_with_the_minimum_named(): void
    {
        $this->api('POST', '/v2/wallets/fund', ['currency' => 'NGN', 'amount' => 500])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['amount']]]);

        $this->assertSame(0, WalletFunding::count());
    }

    public function test_the_points_wallet_cannot_be_funded(): void
    {
        $this->api('POST', '/v2/wallets/fund', ['currency' => 'RCOIN', 'amount' => 100])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['currency']]]);
    }

    // ---------------------------------------------------------- authorising

    public function test_authorising_without_naming_a_method_is_a_validation_error(): void
    {
        $this->givePin();
        $this->fundWallet();

        $session = $this->placeOrder()->assertCreated()->json('data.payment.id');

        $this->api('POST', '/v2/payments/'.$session.'/pay', [], 'tap-pay-1')
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['method']]]);
    }

    /**
     * Placing a second order for the same basket cancels the first without touching
     * the first's payment session. The delegate checks the SESSION'S state, not the
     * order's, so paying that session would resurrect a cancelled order.
     */
    public function test_a_session_whose_order_was_cancelled_cannot_be_paid(): void
    {
        $this->givePin();
        $this->fundWallet();

        $session = $this->placeOrder()->assertCreated()->json('data.payment.id');

        Order::sole()->update(['order_status' => OrderStatus::Cancelled]);

        $token = $this->api('POST', '/v2/transaction-pin/verify', ['pin' => '8317'], null)
            ->assertOk()
            ->json('data.auth_token');

        $this->api('POST', '/v2/payments/'.$session.'/pay', [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ], 'tap-pay-2')
            ->assertStatus(409)
            ->assertJsonPath('error.type', 'order_not_payable');

        $this->assertSame('cancelled', Order::sole()->order_status->value);
    }

    /**
     * The email gate runs BEFORE the idempotency key is claimed. Otherwise the 403
     * was remembered against the key, and a customer who verified their email and
     * retried got the same refusal for a day.
     */
    public function test_the_email_gate_is_answered_fresh_rather_than_remembered_against_the_key(): void
    {
        $this->givePin();
        $this->fundWallet();
        $this->user->forceFill(['email_verified_at' => null])->save();

        $cartId = $this->cart();

        $this->api('POST', '/v2/checkout/orders', [
            'cart_id' => $cartId, 'payment_method' => 'wallet', 'currency' => 'USD',
        ])->assertForbidden()->assertJsonPath('error.type', 'email_verification_required');

        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->api('POST', '/v2/checkout/orders', [
            'cart_id' => $cartId, 'payment_method' => 'wallet', 'currency' => 'USD',
        ])->assertCreated();
    }

    /**
     * Setting a PIN over an existing one would be a way around the change flow, which
     * requires the old PIN and sits behind the new-device hold.
     */
    public function test_a_pin_cannot_be_silently_replaced_by_setting_a_new_one(): void
    {
        $this->givePin('8317');

        $this->api('POST', '/v2/transaction-pin', ['pin' => '5274', 'pin_confirmation' => '5274'])
            ->assertStatus(422)
            ->assertJsonPath('error.type', 'transaction_pin_already_set');
    }

    public function test_a_wrong_pin_is_refused(): void
    {
        $this->givePin('8317');

        $this->api('POST', '/v2/transaction-pin/verify', ['pin' => '5274'], null)
            ->assertStatus(422);
    }

    public function test_a_four_digit_pin_is_required(): void
    {
        $this->api('POST', '/v2/transaction-pin', ['pin' => '12', 'pin_confirmation' => '12'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['pin']]]);
    }
}
