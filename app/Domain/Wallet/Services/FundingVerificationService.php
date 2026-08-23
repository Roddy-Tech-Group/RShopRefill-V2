<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Models\PaymentAttempt;
use App\Models\WalletFunding;
use Illuminate\Support\Facades\Log;

class FundingVerificationService
{
    public function __construct(
        private readonly PaymentGatewayFactory $paymentGatewayFactory
    ) {}

    /**
     * Verify a wallet funding transaction server-to-server and check for tampering.
     */
    public function verify(WalletFunding $funding): bool
    {
        $providerName = $funding->gateway;

        try {
            $provider = $this->paymentGatewayFactory->getProvider($providerName);
        } catch (\InvalidArgumentException $e) {
            Log::error('Unsupported payment provider resolved for wallet funding.', [
                'reference' => $funding->reference,
                'gateway' => $providerName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($provider instanceof FlutterwavePaymentProvider) {
            $verification = $provider->verifyByReference($funding->reference);

            if (! $verification) {
                Log::warning('Flutterwave verification failed - no transaction data returned', [
                    'reference' => $funding->reference,
                ]);

                return false;
            }

            // Extract and compare response attributes to prevent tampering
            $flwStatus = $verification['status'] ?? null;
            $flwAmount = (float) ($verification['amount'] ?? 0.0);
            $flwCurrency = strtoupper($verification['currency'] ?? '');

            // 1. Status Check
            if ($flwStatus !== 'successful' && $flwStatus !== 'success') {
                Log::warning('Flutterwave verification failed - status is not successful', [
                    'reference' => $funding->reference,
                    'status' => $flwStatus,
                ]);

                return false;
            }

            // 2 + 3. Tamper prevention: amount and currency checks, against what
            // Flutterwave was actually ASKED TO CHARGE. The gateway charges the
            // display-currency figure (requested_amount / display_currency); the
            // wallet-currency figures on the funding are what gets credited, not
            // what was charged. Comparing against the wallet figures made every
            // funding whose display currency differed from the wallet currency a
            // false TAMPER: card charged, funding failed, wallet never credited.
            $expectedAmount = (float) ($funding->requested_amount ?: $funding->amount);
            if ($flwAmount < $expectedAmount) {
                Log::error('TAMPER DETECTED: Flutterwave verified amount is lower than charged', [
                    'reference' => $funding->reference,
                    'expected' => $expectedAmount,
                    'received' => $flwAmount,
                ]);

                return false;
            }

            $expectedCurrency = strtoupper($funding->display_currency ?: $funding->currency->value);
            if ($expectedCurrency !== $flwCurrency) {
                Log::error('TAMPER DETECTED: Flutterwave verified currency mismatch', [
                    'reference' => $funding->reference,
                    'expected' => $expectedCurrency,
                    'received' => $flwCurrency,
                ]);

                return false;
            }

            // Update funding properties on success
            $funding->gateway_reference = $verification['transaction_id'];
            $funding->verification_payload_snapshot = $verification['raw'] ?? $verification;
            $funding->verified_at = now();
            $funding->save();

            return true;
        }

        // Every non-Flutterwave gateway used to fall through to this log line and
        // return false, which the caller treats as a failed funding. For crypto that
        // meant: the customer's coins land, NOWPayments confirms the payment, and
        // then our own code marks the deposit failed and never credits the wallet.
        // Retrying could not help either, because the attempt was already Paid so
        // VerifyPaymentJob short-circuited before reaching here.
        //
        // The generic path below verifies through the provider contract, so any
        // gateway implementing PaymentProviderInterface works without another
        // bespoke branch here.
        return $this->verifyViaPaymentAttempt($funding, $provider, $providerName);
    }

    /**
     * Provider-agnostic verification for gateways whose funding is settled against
     * a PaymentAttempt (currently NOWPayments; anything future implementing the
     * interface is covered automatically).
     *
     * Server-to-server verification is delegated to the provider — the gateway is
     * the only trustworthy source for whether money actually arrived. For crypto
     * the tamper vector Flutterwave guards against does not exist: the customer
     * cannot alter an on-chain amount after the fact, and the provider already
     * enforces zero-tolerance full payment before it will report Paid.
     */
    private function verifyViaPaymentAttempt(WalletFunding $funding, PaymentProviderInterface $provider, string $providerName): bool
    {
        $attempt = PaymentAttempt::where('payable_type', WalletFunding::class)
            ->where('payable_id', $funding->id)
            ->latest('created_at')
            ->first();

        if (! $attempt) {
            Log::error('Wallet funding verification failed - no payment attempt linked', [
                'reference' => $funding->reference,
                'gateway' => $providerName,
            ]);

            return false;
        }

        // Already settled by an earlier verification (e.g. VerifyPaymentJob ran the
        // provider check before dispatching here). Re-hitting the gateway would be
        // a redundant round trip for a state that cannot regress.
        $verified = $attempt->payment_status === PaymentStatus::Paid
            || $provider->verifyPayment($attempt);

        if (! $verified) {
            Log::warning('Wallet funding verification failed - gateway does not report payment as settled', [
                'reference' => $funding->reference,
                'gateway' => $providerName,
                'attempt_id' => $attempt->id,
                'payment_status' => $attempt->payment_status?->value,
            ]);

            return false;
        }

        $funding->gateway_reference = $attempt->gateway_reference;
        $funding->verification_payload_snapshot = $attempt->verification_payload ?? [];
        $funding->verified_at = now();
        $funding->save();

        return true;
    }
}
