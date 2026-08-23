<?php

namespace Tests\Feature\Wallet;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\WalletPaymentProvider;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Wallet\Events\TransactionPinCreated;
use App\Domain\Wallet\Events\TransactionPinLocked;
use App\Domain\Wallet\Events\TransactionPinVerificationFailed;
use App\Domain\Wallet\Services\TransactionPinService;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TransactionPinTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->user = User::factory()->create(['password' => Hash::make('password123')]);
    }

    public function test_user_can_setup_pin_and_triggers_event()
    {
        Event::fake([TransactionPinCreated::class]);

        $response = $this->actingAs($this->user)->postJson('/api/wallets/pin/setup', [
            'pin' => '9482',
            'pin_confirmation' => '9482',
        ]);

        $response->assertStatus(201);
        $this->assertTrue($this->user->fresh()->hasTransactionPin());
        $this->assertTrue(Hash::check('9482', $this->user->fresh()->transaction_pin));

        Event::assertDispatched(TransactionPinCreated::class);
    }

    public function test_weak_pin_is_rejected()
    {
        $response = $this->actingAs($this->user)->postJson('/api/wallets/pin/setup', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_user_can_verify_pin_and_get_token()
    {
        $this->user->transaction_pin = Hash::make('9482');
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/api/wallets/pin/verify', [
            'pin' => '9482',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['auth_token']);

        $token = $response->json('auth_token');
        $this->assertTrue(Cache::has("pin_auth_{$this->user->id}_{$token}"));
    }

    public function test_an_auth_token_can_be_claimed_exactly_once()
    {
        $this->user->transaction_pin = Hash::make('9482');
        $this->user->save();

        $service = app(TransactionPinService::class);
        $token = $service->verifyPin($this->user, '9482');

        // First claim wins and burns the token; every later claim is refused,
        // so a double-clicked Pay can never double-authorize one PIN entry.
        $this->assertTrue($service->claimAuthToken($this->user, $token));
        $this->assertFalse($service->claimAuthToken($this->user, $token));
        $this->assertFalse(Cache::has("pin_auth_{$this->user->id}_{$token}"));
    }

    public function test_a_claim_on_an_unknown_token_is_refused()
    {
        $service = app(TransactionPinService::class);

        $this->assertFalse($service->claimAuthToken($this->user, 'auth_pin_forged'));
    }

    public function test_invalid_pin_increments_attempts()
    {
        Event::fake([TransactionPinVerificationFailed::class]);
        $this->user->transaction_pin = Hash::make('9482');
        $this->user->save();

        $response = $this->actingAs($this->user)->postJson('/api/wallets/pin/verify', [
            'pin' => '1112',
        ]);

        $response->assertStatus(422);
        $this->assertEquals(1, $this->user->fresh()->transaction_pin_attempts);
        Event::assertDispatched(TransactionPinVerificationFailed::class);
    }

    public function test_pin_locks_out_after_max_attempts()
    {
        Event::fake([TransactionPinLocked::class]);
        $this->user->transaction_pin = Hash::make('9482');
        $this->user->save();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user)->postJson('/api/wallets/pin/verify', ['pin' => '1112']);
        }

        $this->assertTrue($this->user->fresh()->isTransactionPinLocked());
        Event::assertDispatched(TransactionPinLocked::class);

        // Even correct PIN should fail when locked
        $response = $this->actingAs($this->user)->postJson('/api/wallets/pin/verify', ['pin' => '9482']);
        $response->assertStatus(422)
            ->assertJsonPath('errors.pin.0', 'Too many failed attempts. Try again later.');
    }

    public function test_wallet_payment_flow_requires_pin()
    {
        // 1. Setup user with Wallet and PIN
        $this->user->transaction_pin = Hash::make('9482');
        $this->user->save();
        $wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'balance' => 50.00,
            'locked_balance' => 0.00,
        ]);

        // 2. Mock an order and attempt
        $order = Order::forceCreate([
            'user_id' => $this->user->id,
            'order_number' => 'TEST-123',
            'display_currency' => 'USD',
            'settlement_currency' => 'USD',
            'order_status' => OrderStatus::Pending,
            'payment_status' => PaymentStatus::Pending,
            'fulfillment_status' => FulfillmentStatus::NotStarted,
            'payment_method' => 'wallet',
            'subtotal_amount' => 10.00,
            'markup_amount' => 0.00,
            'total_amount' => 10.00,
        ]);

        $attempt = PaymentAttempt::forceCreate([
            'user_id' => $this->user->id,
            'order_id' => $order->id,
            'gateway' => 'wallet',
            'amount' => 10.00,
            'currency' => 'USD',
            'payment_status' => PaymentStatus::Pending,
            'idempotency_key' => 'TEST-123-KEY',
        ]);

        // 3. Initialize Payment Session
        $sessionService = app(PaymentSessionService::class);
        $providerInitData = app(WalletPaymentProvider::class)->initializePayment($attempt);
        $session = $sessionService->createForOrder($order, $attempt, $providerInitData);

        // Assert session waits for customer action (since PIN is set)
        $this->assertEquals('awaiting_customer_action', $session->fresh()->status);

        // Assert funds are NOT locked yet
        $this->assertEquals(0, $wallet->fresh()->locked_balance);

        // 4. Verify PIN to get Auth Token
        $verifyRes = $this->actingAs($this->user)->postJson('/api/wallets/pin/verify', ['pin' => '9482']);
        $token = $verifyRes->json('auth_token');

        // 5. Pay using the token
        $payRes = $this->actingAs($this->user)->postJson("/api/payment-sessions/{$session->id}/pay", [
            'method' => 'wallet',
            'details' => ['auth_token' => $token],
        ]);

        $payRes->assertStatus(200);
        $this->assertEquals('confirmed', $session->fresh()->status);

        // Assert funds are NOW locked
        $this->assertEquals(10.00, $wallet->fresh()->locked_balance);

        // Auth token consumed
        $this->assertFalse(Cache::has("pin_auth_{$this->user->id}_{$token}"));
    }
}
