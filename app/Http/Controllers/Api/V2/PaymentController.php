<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Order\Enums\OrderStatus;
use App\Http\Controllers\Api\PaymentSessionController as StorefrontPaymentSessionController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\AuthorisePaymentRequest;
use App\Http\Resources\V2\PaymentSessionResource;
use App\Jobs\VerifyPaymentJob;
use App\Models\PaymentSession;
use App\Support\ApiV2Error;
use App\Support\ApiV2PaymentFailures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Paying for something, and finding out whether it worked.
 *
 * Three of these actions DELEGATE to the storefront's own PaymentSessionController
 * rather than reimplementing it, and that is the most important decision in this
 * file. Its `pay` method is around four hundred lines: the per-rail kill switches,
 * the per-currency method allowlist, category-aware gateway routing with fiat
 * failover, and the atomic claim on a wallet PIN token that stops a double tap
 * double-locking a wallet. (The risk gate, the payment-insights backoff and the
 * spending cap run earlier, when the order or funding is CREATED, in CheckoutService
 * and WalletFundingService.) A second implementation of that would be the single
 * most dangerous piece of code in this repository, and it would drift from the first
 * within a month.
 *
 * So the ACTION is theirs and the CONTRACT is ours: on success the delegated body is
 * discarded and the session is re-read into the shape the app was promised. On
 * failure the response passes through NormaliseV2ErrorEnvelope, which keeps their
 * customer-ready message and flags while giving the app the envelope it reads, with
 * one exception: their catch-all (flagged payment_failed) becomes the payment_failed
 * type, so the app routes on a type rather than on the wording.
 *
 * Two guards sit in FRONT of the delegate, because it checks the session's own state
 * and not the order's: a session whose order has since been cancelled (superseded by
 * a later checkout) or has already been paid is refused here, and a second tap racing
 * the first on the same session waits for it rather than running beside it.
 */
class PaymentController extends Controller
{
    /** How long one authorisation holds the session against a concurrent one. */
    private const PAY_LOCK_SECONDS = 30;

    public function show(Request $request, string $session): JsonResponse
    {
        $found = $this->ownedSession($request, $session);

        return $found instanceof PaymentSession
            ? $this->respond($found)
            : $found;
    }

    /**
     * "Has the money landed?" Called when the app comes back from a gateway by deep
     * link, and then on a slowing schedule while the customer waits.
     *
     * VerifyPaymentJob is the authoritative settlement chain, run inline: it takes a
     * row lock and carries the duplicate-payment guard. That guard is the reason this
     * endpoint is safe to call as often as the app likes, and the reason it must not
     * be replaced with a bespoke verification here. A double tap, or a tap racing the
     * gateway's own webhook, confirms once.
     *
     * Deliberately NOT behind an idempotency key. It has to be callable repeatedly by
     * design, and the app's recovery from a missed deep link is precisely to keep
     * asking.
     */
    public function verify(Request $request, string $session): JsonResponse
    {
        $found = $this->ownedSession($request, $session);

        if (! $found instanceof PaymentSession) {
            return $found;
        }

        if ($found->status === 'confirmed') {
            // Already settled, by the webhook or an earlier call. Not an error: this is
            // the common case when a customer returns to the app slowly.
            return $this->respond($found);
        }

        $attempt = $found->paymentAttempt;

        if ($attempt === null) {
            return ApiV2Error::make('payment_not_found', 'That payment could not be found.', 404);
        }

        try {
            VerifyPaymentJob::dispatchSync($attempt);
        } catch (Throwable $e) {
            $envelope = ApiV2PaymentFailures::toEnvelope($e);

            if ($envelope !== null) {
                return $envelope;
            }

            // Verification failing is not the same as the payment failing. The money
            // may well have arrived, and the webhook and the reconcilers will still
            // settle it, so the app is told to keep asking rather than told it failed.
            Log::error('api.v2.payment_verification_failed', [
                'session_id' => $found->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiV2Error::make(
                'verification_unavailable',
                'We could not confirm that payment just now. Please try again in a moment.',
                503,
            );
        }

        return $this->respond($found->fresh(['paymentAttempt.order']));
    }

    /**
     * Authorise the session with a chosen method: a card, mobile money, or a wallet
     * PIN token.
     *
     * Delegated. See the note on this class.
     */
    public function pay(AuthorisePaymentRequest $request, string $session): JsonResponse
    {
        $found = $this->ownedSession($request, $session);

        if (! $found instanceof PaymentSession) {
            return $found;
        }

        if ($refusal = $this->notPayable($found)) {
            return $refusal;
        }

        // One authorisation at a time per session. The idempotency key stops the
        // SAME tap running twice; this stops two DIFFERENT taps (two keys, two PIN
        // tokens) both passing the delegate's state check before either has written,
        // which on a wallet payment would hold the amount twice.
        $lock = Cache::lock('v2:pay:'.$found->id, self::PAY_LOCK_SECONDS);

        if (! $lock->get()) {
            return ApiV2Error::make(
                'request_in_progress',
                'That payment is still being processed. Please wait a moment.',
                409,
            );
        }

        try {
            return $this->delegate(
                fn (StorefrontPaymentSessionController $controller) => $controller->pay($session, $request),
                $found,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Why this session can no longer be paid, or null when it still can.
     *
     * The delegate checks the SESSION'S terminal state. It does not check the
     * order's, and placing a second order for the same cart cancels the first
     * without touching the first's session. Paying that session would move a
     * cancelled order back to processing and fulfil it beside the live one.
     */
    private function notPayable(PaymentSession $session): ?JsonResponse
    {
        if ($session->expires_at !== null && $session->expires_at->isPast()
            && ! in_array($session->status, ['confirmed'], true)) {
            return ApiV2Error::make(
                'payment_session_expired',
                'This payment has expired. Please start the checkout again.',
                409,
            );
        }

        $order = $session->paymentAttempt?->order;

        if ($order === null) {
            return null;
        }

        if (in_array($order->order_status, [OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded], true)) {
            return ApiV2Error::make(
                'order_not_payable',
                'This order is no longer open for payment. Please start the checkout again.',
                409,
            );
        }

        if (in_array($order->payment_status->value, ['paid', 'refunded', 'partially_refunded'], true)) {
            return ApiV2Error::make(
                'order_already_paid',
                'This order has already been paid.',
                409,
            );
        }

        return null;
    }

    /**
     * Abandon a payment. Delegated because cancelling releases a coupon reservation
     * and unwinds an order's pending state, and doing half of that would leave a
     * promotion held by an order nobody will ever pay.
     */
    public function cancel(Request $request, string $session): JsonResponse
    {
        $found = $this->ownedSession($request, $session);

        if (! $found instanceof PaymentSession) {
            return $found;
        }

        return $this->delegate(
            fn (StorefrontPaymentSessionController $controller) => $controller->cancel($session, $request),
            $found,
        );
    }

    /**
     * A follow-up payment for the shortfall on an underpaid crypto session.
     *
     * Without this a customer whose exchange deducted a withdrawal fee has paid,
     * received nothing, and has no self-service way to make up the difference.
     */
    public function topUp(Request $request, string $session): JsonResponse
    {
        $found = $this->ownedSession($request, $session);

        if (! $found instanceof PaymentSession) {
            return $found;
        }

        return $this->delegate(
            fn (StorefrontPaymentSessionController $controller) => $controller->topUp($session),
            $found,
        );
    }

    /**
     * Run a storefront action, then answer in the v2 shape.
     *
     * On success the delegated body is discarded and the session is re-read, so the
     * app gets the contract it was promised rather than a body that was never designed
     * as one. On failure the response is returned as-is and NormaliseV2ErrorEnvelope
     * converts it, keeping the customer-ready message the guard wrote.
     *
     * @param  callable(StorefrontPaymentSessionController): mixed  $action
     */
    private function delegate(callable $action, PaymentSession $session): JsonResponse
    {
        try {
            $response = $action(app(StorefrontPaymentSessionController::class));
        } catch (Throwable $e) {
            $envelope = ApiV2PaymentFailures::toEnvelope($e);

            if ($envelope !== null) {
                return $envelope;
            }

            throw $e;
        }

        $status = $response instanceof Response
            ? $response->getStatusCode()
            : 200;

        if ($status >= 400) {
            if (! $response instanceof JsonResponse) {
                return ApiV2Error::fromHttpStatus($status);
            }

            // Their refusal, their wording, EXCEPT the catch-all, which the
            // storefront marks with payment_failed: an exception it caught, already
            // logged there with the exception's own text. The app gets a stable
            // type to route on.
            if (($response->getData(true)['payment_failed'] ?? false) === true) {
                Log::warning('api.v2.payment_delegate_catch_all', [
                    'session_id' => $session->id,
                ]);

                return ApiV2Error::make(
                    'payment_failed',
                    'This payment could not be processed. Please try again or choose another payment method.',
                    $status,
                );
            }

            // The rest pass through the normaliser, which gives them our shape.
            return $response;
        }

        return $this->respond($session->fresh(['paymentAttempt.order']));
    }

    /**
     * The session, if it belongs to the caller.
     *
     * A payment session id is a uuid, but ownership is still checked rather than
     * assumed, and a session belonging to somebody else answers exactly like one that
     * never existed, so the endpoint cannot be used to confirm that an id is real.
     */
    private function ownedSession(Request $request, string $id): PaymentSession|JsonResponse
    {
        $session = PaymentSession::with('paymentAttempt.order')->find($id);

        if ($session === null || $session->paymentAttempt?->user_id !== $request->user()->id) {
            return ApiV2Error::make('payment_not_found', 'That payment could not be found.', 404);
        }

        return $session;
    }

    private function respond(?PaymentSession $session): JsonResponse
    {
        if ($session === null) {
            return ApiV2Error::make('payment_not_found', 'That payment could not be found.', 404);
        }

        return response()->json([
            'data' => (new PaymentSessionResource($session))->resolve(),
        ]);
    }
}
