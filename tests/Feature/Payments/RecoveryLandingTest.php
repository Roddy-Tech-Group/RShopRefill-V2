<?php

namespace Tests\Feature\Payments;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Mail\Transactions\PaymentRecoveryMail;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\Communication\Support\UnsubscribeLink;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\PaymentInsights\Enums\IneligibilityReason;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Services\RecoveryEligibilityService;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Wallet\Events\FundingFailed;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\SiteSetting;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a customer meets after a failed payment: the page the recovery email
 * lands them on, whether the robot writes at all, and the words it uses for a
 * wallet top-up versus an order. The September audit found the retry link
 * dead-ending on a failed order's page, the funding retry dropping people on
 * a bare dashboard, and every sentence written as if a top-up were an order.
 */
class RecoveryLandingTest extends TestCase
{
    use RefreshDatabase;

    private function orderSession(User $user, array $orderAttributes = []): PaymentSession
    {
        $order = Order::factory()->create(array_merge(['user_id' => $user->id], $orderAttributes));

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'land_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'pending',
        ]);

        return app(PaymentSessionService::class)->createForOrder($order, $attempt, []);
    }

    /**
     * @return array{0: WalletFunding, 1: PaymentSession}
     */
    private function fundingSession(User $user): array
    {
        $wallet = Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 0.0]);
        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-USD-'.Str::random(8),
            'currency' => 'USD',
            'amount' => 50.0,
            'gateway' => 'flutterwave',
            'status' => 'pending',
        ]);
        $attempt = PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => 'land_'.Str::random(12),
            'currency' => 'USD',
            'amount' => 50.0,
            'payment_status' => 'pending',
        ]);

        return [$funding, app(PaymentSessionService::class)->createForWalletFunding($funding, $attempt, [])];
    }

    private function recoveryFor(PaymentSession $session, User $user, string $code = 'bank_declined'): TransactionRecovery
    {
        return TransactionRecovery::create([
            'payment_session_id' => $session->id,
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'failure_reason_code' => $code,
            'token' => Str::random(40),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
    }

    // ------------------------------------------------------------ landing

    public function test_a_declined_order_offers_a_way_back_to_checkout(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatus::Failed,
            'payment_status' => PaymentStatus::Failed,
        ]);

        $this->actingAs($user)->withoutVite()
            ->get(route('shop.order', ['orderNumber' => $order->order_number]))
            ->assertOk()
            ->assertSee('Try again')
            ->assertSee(route('shop.checkout'));
    }

    public function test_an_expired_checkout_offers_a_way_back_too(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'order_status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Unpaid,
        ]);

        $this->actingAs($user)->withoutVite()
            ->get(route('shop.order', ['orderNumber' => $order->order_number]))
            ->assertOk()
            ->assertSee('Try again');
    }

    public function test_a_paid_order_never_shows_a_retry(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->withoutVite()
            ->get(route('shop.order', ['orderNumber' => $order->order_number]))
            ->assertOk()
            ->assertDontSee('Try again')
            ->assertDontSee('Complete payment');
    }

    public function test_the_fund_sheet_opens_prefilled_from_the_recovery_deep_link(): void
    {
        $user = User::factory()->create();
        Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 0.0]);
        Wallet::create(['user_id' => $user->id, 'currency' => Currency::NGN, 'balance' => 0.0]);

        // The dashboard mounts one fund sheet per wallet. Only the one for the
        // deep-linked currency opens, and it carries the failed amount.
        $html = $this->actingAs($user)->withoutVite()
            ->get(route('dashboard', ['fund' => 'usd', 'amount' => '50.0000']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'open: true,'), 'exactly one sheet opens on load');
        $this->assertStringContainsString('&quot;amount&quot;:&quot;50&quot;', $html);

        // Without the deep link nothing opens.
        $plain = $this->actingAs($user)->withoutVite()->get(route('dashboard'))->assertOk()->getContent();
        $this->assertSame(0, substr_count($plain, 'open: true,'));
    }

    // ------------------------------------------------------- eligibility

    public function test_a_fault_that_was_ours_never_gets_a_try_again_email(): void
    {
        $user = User::factory()->create();
        $session = $this->orderSession($user);

        // The exact failure of 2026-09-09, as the pay endpoint records it.
        app(PaymentSessionService::class)->failSession($session, 'Failed to initialize mobile money: This payment option is not allowed for your currency', [
            'status' => 'failed',
            'failure' => ['provider_code' => null, 'provider_message' => 'This payment option is not allowed for your currency', 'http_status' => 400],
        ]);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('gateway_config_error', $outcome->failure_reason_code);

        $verdict = app(RecoveryEligibilityService::class)->evaluate($outcome);

        $this->assertFalse($verdict['eligible']);
        $this->assertSame(IneligibilityReason::PlatformFault, $verdict['reason']);
    }

    public function test_a_platform_error_is_also_ours_even_though_a_retry_might_work(): void
    {
        $user = User::factory()->create();
        $session = $this->orderSession($user);

        app(PaymentSessionService::class)->failSession($session, 'Internal server error while creating the charge', ['status' => 'failed']);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('system_error', $outcome->failure_reason_code);
        $this->assertTrue($outcome->retryable);

        $this->assertSame(IneligibilityReason::PlatformFault, app(RecoveryEligibilityService::class)->evaluate($outcome)['reason']);
    }

    // -------------------------------------------------------------- copy

    public function test_wallet_top_ups_are_never_called_orders(): void
    {
        $funding = RecoveryCopy::for('session_expired', ProductLine::WalletFunding);
        $this->assertSame('Your top-up timed out', $funding['headline']);
        $this->assertStringNotContainsString('order', strtolower($funding['guidance']));
        $this->assertStringNotContainsString('items', strtolower($funding['guidance']));

        // Telling someone whose wallet top-up was declined to "pay from your
        // wallet balance" is the sentence the audit flagged.
        $declined = RecoveryCopy::for('insufficient_funds', ProductLine::WalletFunding);
        $this->assertStringNotContainsString('wallet balance', (string) $declined['hint']);

        $this->assertSame('Your wallet top-up is still waiting for you', RecoveryCopy::reminderSubject('session_expired', ProductLine::WalletFunding));
        $this->assertSame('Your order is still waiting for you', RecoveryCopy::reminderSubject('session_expired', ProductLine::Checkout));

        // Checkout copy is untouched.
        $this->assertSame('Your checkout timed out', RecoveryCopy::for('session_expired', ProductLine::Checkout)['headline']);
        $this->assertSame('Your checkout timed out', RecoveryCopy::for('session_expired')['headline']);
    }

    public function test_the_recovery_email_and_page_speak_in_top_up_terms_for_a_funding(): void
    {
        $user = User::factory()->create();
        [, $session] = $this->fundingSession($user);
        $recovery = $this->recoveryFor($session, $user, 'session_expired');

        $this->assertSame(ProductLine::WalletFunding, $recovery->productLine());

        $html = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringContainsString('Your top-up timed out', $html);
        $this->assertStringContainsString('Wallet top-up', $html);
        $this->assertStringContainsString('Complete your top-up', $html);
        $this->assertStringNotContainsString('your order', strtolower($html));

        $this->withoutVite()->get(route('recovery.show', $recovery->token))
            ->assertOk()
            ->assertSee('Complete your top-up')
            ->assertSee('back to your wallet');
    }

    public function test_the_recovery_email_still_names_the_order_for_a_checkout(): void
    {
        $user = User::factory()->create();
        $session = $this->orderSession($user);
        $recovery = $this->recoveryFor($session, $user, 'session_expired');

        $this->assertSame(ProductLine::Checkout, $recovery->productLine());

        $html = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringContainsString('Order '.$session->order->order_number, $html);
        $this->assertStringContainsString('Complete your payment', $html);
    }

    // ------------------------------------------------------- email hygiene

    public function test_the_recovery_email_greets_by_name_and_states_the_expiry_relatively(): void
    {
        $user = User::factory()->create(['name' => 'Ada Mensah']);
        $recovery = $this->recoveryFor($this->orderSession($user), $user);
        $recovery->update(['expires_at' => now()->addHours(48)]);

        $html = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringContainsString('Hi Ada Mensah,', $html);
        // Customers sit in every timezone and we store none: no clock time.
        $this->assertStringContainsString('This link works for the next 48 hours.', $html);
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}\s?(am|pm)/i', $html, 'no clock time may appear in the email');

        // The reminder, a day in, says what is actually left.
        $this->travel(20)->hours();
        $reminder = (new PaymentRecoveryMail($recovery->fresh(), isReminder: true))->render();
        $this->assertStringContainsString('This link works for about 28 more hours.', $reminder);
    }

    public function test_the_recovery_email_carries_a_working_unsubscribe(): void
    {
        $user = User::factory()->create();
        $recovery = $this->recoveryFor($this->orderSession($user), $user);
        $mail = new PaymentRecoveryMail($recovery);

        $unsubscribe = $mail->unsubscribeUrl();
        $this->assertNotNull($unsubscribe);
        $this->assertStringContainsString('/api/email/unsubscribe/'.$user->id.'/payment_recovery', $unsubscribe);
        $this->assertStringContainsString('signature=', $unsubscribe);

        $headers = $mail->headers()->text;
        $this->assertSame("<{$unsubscribe}>", $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);

        $html = $mail->render();
        $this->assertStringContainsString(route('dashboard.notifications'), $html);
        $this->assertStringContainsString('unsubscribe', $html);

        // The mail client's one-click POST: no login, no CSRF, just done.
        $this->post($unsubscribe)->assertOk();
        $this->assertFalse(app(PreferenceManager::class)->isAllowed($user->fresh(), CommunicationCategory::PaymentRecovery, CommunicationChannel::Email));

        // The footer link a person clicks shows a confirmation.
        $this->withoutVite()->get($unsubscribe)->assertOk()->assertSee('You are unsubscribed');
    }

    public function test_an_unsigned_unsubscribe_link_does_nothing(): void
    {
        $user = User::factory()->create();

        $this->post(route('email.unsubscribe', ['user' => $user->id, 'category' => 'payment_recovery']))
            ->assertForbidden();

        $this->assertTrue(app(PreferenceManager::class)->isAllowed($user->fresh(), CommunicationCategory::PaymentRecovery, CommunicationChannel::Email));
    }

    public function test_security_mail_cannot_be_unsubscribed_even_with_a_valid_link(): void
    {
        $user = User::factory()->create();
        $link = UnsubscribeLink::for($user, CommunicationCategory::PasswordReset);

        $this->post($link)->assertStatus(422);
        $this->withoutVite()->get($link)->assertOk()->assertSee('cannot be turned off');
    }

    public function test_a_swept_payment_is_never_asked_to_pay_again(): void
    {
        $user = User::factory()->create();
        $session = $this->orderSession($user);

        // The sweeper's verdict: the gateway never answered.
        app(PaymentSessionService::class)->failSession($session, 'orphaned_timeout', ['status' => 'failed']);

        $outcome = PaymentOutcome::where('payment_session_id', $session->id)->firstOrFail();
        $this->assertSame('orphaned_timeout', $outcome->failure_reason_code);
        $this->assertSame(IneligibilityReason::OutcomeUncertain, app(RecoveryEligibilityService::class)->evaluate($outcome)['reason']);

        // Belt and braces: even a recovery that already exists for it shows no
        // pay button and never claims nothing was charged.
        $recovery = $this->recoveryFor($session, $user, 'orphaned_timeout');
        $html = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringNotContainsString('Complete your payment', $html);
        $this->assertStringNotContainsString('Nothing was charged', $html);
        $this->assertStringContainsString('you do not need to pay again', $html);
    }

    public function test_the_email_support_link_follows_the_site_setting(): void
    {
        SiteSetting::put('contact.whatsapp_number', '+237 676 700 173', 'contact');

        $user = User::factory()->create();
        $recovery = $this->recoveryFor($this->orderSession($user), $user);

        $html = (new PaymentRecoveryMail($recovery))->render();
        $this->assertStringContainsString('https://wa.me/237676700173', $html);
        $this->assertStringNotContainsString('wa.me/19402386229', $html);
    }

    // ------------------------------------------------ funding-failed notice

    public function test_the_immediate_funding_failed_notice_uses_the_same_voice_as_the_recovery_email(): void
    {
        $user = User::factory()->create();
        [$funding] = $this->fundingSession($user);
        $funding->update([
            'status' => 'failed',
            'provider_payload_snapshot' => ['failure' => ['provider_code' => 'Insufficient Funds', 'provider_message' => 'Insufficient Funds', 'http_status' => null]],
        ]);

        event(new FundingFailed($funding->fresh(), 'Insufficient Funds'));

        $notice = Notification::where('user_id', $user->id)
            ->where('type', CommunicationCategory::FundingFailed->value)
            ->firstOrFail();

        $this->assertSame('Your wallet top-up did not go through', $notice->title);
        $this->assertStringContainsString('USD 50.00 top-up was not completed', $notice->message);
        $this->assertStringContainsString('declined for insufficient funds', $notice->message);
        $this->assertStringNotContainsString('Reason:', $notice->message);
        $this->assertStringNotContainsString('wallet balance', $notice->message);
        $this->assertSame('insufficient_funds', $notice->metadata['failure_reason_code'] ?? null);
    }
}
