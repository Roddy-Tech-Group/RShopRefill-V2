<?php

namespace Tests\Feature\Wallet;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Wallet\Services\FundingVerificationService;
use App\Domain\Wallet\Services\WalletService;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Models\WalletFunding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The false-TAMPER bug: Flutterwave charges the DISPLAY currency figure
 * (requested_amount / display_currency), but verification used to compare the
 * gateway's answer against the wallet-currency figures, so every funding whose
 * display currency differed from the wallet currency was flagged as tampering:
 * card charged, funding failed, wallet never credited, nothing refunded.
 * Verification must compare against what the gateway was asked to charge.
 */
class FundingDisplayCurrencyVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function fundingChargedInNgn(): WalletFunding
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

        return WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.uniqid(),
            'currency' => 'USD',
            'amount' => 50.0,
            'display_currency' => 'NGN',
            'requested_amount' => 76000.0,
            'exchange_rate_snapshot' => 1520.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);
    }

    /**
     * A dollar wallet topped up over MTN or Orange: the display figure is
     * dollars, but the Francophone rail is charged in CFA francs, and the
     * attempt records the franc figure the way the provider writes it.
     */
    private function fundingChargedInFrancs(): WalletFunding
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);

        $funding = WalletFunding::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'reference' => 'FUND-'.uniqid(),
            'currency' => 'USD',
            'amount' => 50.0,
            'display_currency' => 'USD',
            'requested_amount' => 50.0,
            'exchange_rate_snapshot' => 1.0,
            'gateway' => 'flutterwave',
            'status' => FundingStatus::Pending,
        ]);

        PaymentAttempt::create([
            'user_id' => $user->id,
            'payable_type' => WalletFunding::class,
            'payable_id' => $funding->id,
            'gateway' => 'flutterwave',
            'idempotency_key' => $funding->reference,
            'currency' => 'USD',
            'amount' => 50.0,
            'exchange_rate_snapshot' => 1.0,
            'payment_status' => PaymentStatus::Pending,
            'pay_currency' => 'XAF',
            'pay_amount' => 30000.0,
        ]);

        return $funding;
    }

    /**
     * @param  array<string, mixed>  $verification
     */
    private function serviceReturning(array $verification): FundingVerificationService
    {
        $provider = $this->createMock(FlutterwavePaymentProvider::class);
        $provider->method('verifyByReference')->willReturn($verification);

        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->method('getProvider')->willReturn($provider);

        return new FundingVerificationService($factory);
    }

    public function test_a_display_currency_charge_verifies_against_what_was_charged(): void
    {
        $funding = $this->fundingChargedInNgn();

        $service = $this->serviceReturning([
            'status' => 'successful',
            'amount' => 76000.0,
            'currency' => 'NGN',
            'transaction_id' => 'FLW-123',
            'raw' => [],
        ]);

        $this->assertTrue(
            $service->verify($funding),
            'a charge in the display currency must verify, not trip TAMPER',
        );
        $this->assertSame('FLW-123', $funding->fresh()->gateway_reference);
    }

    public function test_a_short_charge_still_trips_the_tamper_guard(): void
    {
        $funding = $this->fundingChargedInNgn();

        $service = $this->serviceReturning([
            'status' => 'successful',
            'amount' => 75000.0,
            'currency' => 'NGN',
            'transaction_id' => 'FLW-124',
            'raw' => [],
        ]);

        $this->assertFalse($service->verify($funding));
    }

    public function test_a_wrong_currency_still_trips_the_tamper_guard(): void
    {
        $funding = $this->fundingChargedInNgn();

        $service = $this->serviceReturning([
            'status' => 'successful',
            'amount' => 76000.0,
            'currency' => 'USD',
            'transaction_id' => 'FLW-125',
            'raw' => [],
        ]);

        $this->assertFalse($service->verify($funding));
    }

    /**
     * The same guard, one step further: a mobile-money top-up on a converted
     * rail is charged in francs the display figure never mentions. Compared
     * against the display figure it would trip TAMPER on every success (paid,
     * never credited); compared against what the rail was asked for, it
     * verifies.
     */
    public function test_a_top_up_charged_in_francs_verifies_against_the_franc_figure(): void
    {
        $funding = $this->fundingChargedInFrancs();

        $service = $this->serviceReturning([
            'status' => 'successful',
            'amount' => 30000.0,
            'currency' => 'XAF',
            'transaction_id' => 'FLW-126',
            'raw' => [],
        ]);

        $this->assertTrue($service->verify($funding), 'the franc charge must verify, not trip TAMPER');
        $this->assertSame('FLW-126', $funding->fresh()->gateway_reference);
    }

    public function test_the_display_figure_no_longer_verifies_a_charge_taken_in_francs(): void
    {
        $funding = $this->fundingChargedInFrancs();

        $service = $this->serviceReturning([
            'status' => 'successful',
            'amount' => 50.0,
            'currency' => 'USD',
            'transaction_id' => 'FLW-127',
            'raw' => [],
        ]);

        $this->assertFalse($service->verify($funding), 'the rail never charged dollars, so dollars cannot prove it was paid');
    }
}
