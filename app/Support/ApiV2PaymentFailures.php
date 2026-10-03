<?php

namespace App\Support;

use App\Domain\Auth\Exceptions\EmailVerificationRequiredException;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Order\Exceptions\InvalidCouponException;
use App\Domain\Payment\Exceptions\GatewayDisabledException;
use App\Domain\Payment\Exceptions\PaymentCategoryIneligibleException;
use App\Domain\PaymentInsights\Exceptions\PaymentBackoffException;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\MissingTransactionPinException;
use App\Domain\Wallet\Exceptions\RateUnavailableException;
use App\Domain\Wallet\Exceptions\WalletFundingException;
use App\Domain\Wallet\Exceptions\WalletOnHoldException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the payment pipeline's refusals into the app's error envelope.
 *
 * Every one of these exceptions already carries a customer-ready sentence, written
 * by whoever built that guard and reviewed alongside it. Those sentences are kept
 * verbatim; only the shape around them is ours.
 *
 * The `type` is what earns this class its existence. Each of these needs a DIFFERENT
 * screen in the app, and they cannot be told apart from a message the marketing team
 * may reword next quarter:
 *
 *   needs_identity_verification         launch the verification flow, then retry
 *   spending_limit_reached              explain the cap and offer verification
 *   held_for_review                     tell them we are looking, and stop retrying
 *   payment_refused                     refused; no reason codes on purpose
 *   payment_paused                      show a countdown (retry_after) and retry
 *   gateway_unavailable                 offer a different payment method
 *   payment_method_not_allowed_for_items  offer a different payment method
 *   insufficient_balance                offer to top up the wallet
 *   transaction_pin_required            send them to set a PIN
 *   wallet_on_hold                      explain, and point at support
 *   coupon_invalid                      let them fix or remove the code
 *   rates_unavailable                   try again shortly
 *   wallet_funding_unavailable          try again shortly, or another method
 *   email_verification_required         open the verify-your-email screen
 *
 * Anything unrecognised is a 500 with nothing leaked, logged for us. Guessing at a
 * customer-facing reason for an exception nobody anticipated is how a refusal ends
 * up telling somebody the wrong thing about their money.
 */
final class ApiV2PaymentFailures
{
    /**
     * @return JsonResponse|null null when this is not a failure we recognise, so
     *                           the caller can rethrow rather than guess
     */
    public static function toEnvelope(Throwable $e): ?JsonResponse
    {
        return match (true) {
            // Crossed the identity threshold. The web response carries the identity
            // provider's own session block, named after the provider; the app gets
            // only what it can act on, because starting a verification is not on
            // /v2 yet and a provider's name never reaches a customer surface.
            $e instanceof KycStepUpRequiredException => ApiV2Error::make(
                'needs_identity_verification',
                $e->getMessage(),
                422,
                [
                    'required_tier' => $e->requiredTier->value,
                    'in_review' => $e->inReview(),
                ],
            ),

            $e instanceof SpendingLimitExceededException => ApiV2Error::make(
                'spending_limit_reached',
                $e->getMessage(),
                422,
                self::payload($e),
            ),

            // Held for a human to look at. The app must NOT retry: retrying is what
            // turns one review into a queue of them.
            $e instanceof RiskReviewRequiredException => ApiV2Error::make(
                'held_for_review',
                $e->getMessage(),
                422,
                self::payload($e),
            ),

            // Refused. Deliberately no reason codes: they would tell somebody
            // probing us which signal they tripped.
            $e instanceof RiskDeniedException => ApiV2Error::make(
                'payment_refused',
                $e->getMessage(),
                403,
            ),

            // The card-testing brake. retry_after is the whole point: the app shows a
            // countdown instead of letting the customer hammer a locked door. The
            // exception exposes the wait as properties rather than a payload, so it
            // is read directly, and Retry-After is set so a generic client waits too.
            $e instanceof PaymentBackoffException => ApiV2Error::make(
                'payment_paused',
                $e->getMessage(),
                429,
                [
                    'retry_after' => max(1, $e->waitSeconds),
                    'locked' => $e->locked,
                ],
            )->header('Retry-After', (string) max(1, $e->waitSeconds)),

            $e instanceof GatewayDisabledException => ApiV2Error::make(
                'gateway_unavailable',
                $e->customerMessage(),
                503,
            ),

            // A cart category this gateway will not process. Nothing was written, so
            // a different method may well work.
            $e instanceof PaymentCategoryIneligibleException => ApiV2Error::make(
                'payment_method_not_allowed_for_items',
                $e->customerMessage(),
                422,
            ),

            $e instanceof InsufficientBalanceException => ApiV2Error::make(
                'insufficient_balance',
                $e->getMessage(),
                422,
            ),

            $e instanceof MissingTransactionPinException => ApiV2Error::make(
                'transaction_pin_required',
                $e->getMessage(),
                422,
            ),

            $e instanceof WalletOnHoldException => ApiV2Error::make(
                'wallet_on_hold',
                $e->getMessage(),
                422,
            ),

            // A coupon that does not apply. The web answers this as a 422 naming the
            // field; without a mapping it was a 500 that also released the key.
            $e instanceof InvalidCouponException => ApiV2Error::make(
                'coupon_invalid',
                $e->getMessage(),
                422,
                ['fields' => ['coupon_code' => [$e->getMessage()]]],
            ),

            // No usable rate for the charge currency: the live one is too old to
            // charge at, or the currency has no active rate at all. The stale
            // message names the pair and its age, which is ours, not the customer's.
            $e instanceof RateUnavailableException => ApiV2Error::make(
                'rates_unavailable',
                'We cannot price that currency right now. Please try again shortly or choose another currency.',
                503,
            ),

            // Wallet funding refused by its own switch or a rail it could not use.
            // Its messages are written for the customer.
            $e instanceof WalletFundingException => ApiV2Error::make(
                'wallet_funding_unavailable',
                $e->getMessage(),
                503,
            ),

            // The service's own email gate, for the paths middleware did not cover.
            $e instanceof EmailVerificationRequiredException => ApiV2Error::make(
                'email_verification_required',
                $e->customerMessage(),
                403,
                ['email_unverified' => true, 'resend_retry_after' => $e->retryAfter],
            ),

            default => null,
        };
    }

    /**
     * The extra context an exception carries for the client, when it carries any.
     *
     * Several of these build their own response payload (a verification session, a
     * retry window). Those are already customer-facing by design, so they are folded
     * into the error object rather than thrown away and re-derived here.
     *
     * @return array<string, mixed>
     */
    private static function payload(Throwable $e): array
    {
        if (! method_exists($e, 'toResponse')) {
            return [];
        }

        try {
            $payload = $e->toResponse();
        } catch (Throwable $inner) {
            Log::warning('api.v2.payment_failure_payload_unreadable', [
                'exception' => $e::class,
                'error' => $inner->getMessage(),
            ]);

            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        // The message is already the envelope's own, so it is not repeated inside.
        unset($payload['message']);

        return $payload;
    }
}
