<?php

namespace App\Jobs;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Interfaces\PaymentProviderInterface;
use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Domain\Payment\Services\PaymentSessionService;
use App\Domain\Payment\Support\CustomerFailureCopy;
use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\Payment\Support\NowPaymentsStatus;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Domain\Shared\Enums\FundingStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Domain\Wallet\Services\WalletFundingService;
use App\Enums\AlertSeverity;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use App\Models\WalletFunding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 10;

    /**
     * Session statuses where one reference is one charge, so the gateway's
     * "failed" on that reference is the end of the story. The inline popup
     * (awaiting_payment) and the hosted page (awaiting_redirect) let a
     * customer try again under the same reference, and a stale refusal there
     * must not close a session a later card may still pay.
     *
     * @var list<string>
     */
    private const SINGLE_CHARGE_SESSION_STATUSES = ['awaiting_confirmation', 'awaiting_customer_action'];

    public function __construct(protected PaymentAttempt $attempt)
    {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('payments');
    }

    public function handle(
        PaymentGatewayFactory $gatewayFactory,
        OrderService $orderService
    ): void {
        Log::info("VerifyPaymentJob: checking payment status for attempt {$this->attempt->id}");

        // Prevent webhook race conditions by wrapping in a transaction with pessimistic lock
        DB::transaction(function () use ($gatewayFactory, $orderService) {
            $attempt = PaymentAttempt::where('id', $this->attempt->id)->lockForUpdate()->first();

            if (! $attempt) {
                return;
            }

            // Failed is final, with one exception: a crypto wallet funding that
            // OUR sweeper failed on the clock while the gateway still had the
            // customer's coins confirming. The gateway never said failed, so
            // when its `finished` IPN arrives, or the hourly reconcile asks, the
            // money is real and must be credited. Only a status the gateway
            // itself reported as terminal closes the door for good.
            if ($attempt->payment_status === PaymentStatus::Failed && ! $this->failedLocallyWhileGatewayStillOpen($attempt)) {
                return;
            }

            // A Paid attempt does NOT always mean the settlement chain ran.
            // The hosted return leg verifies with the gateway (which stamps
            // the attempt Paid) and a crash between that stamp and the order
            // confirmation leaves a paid attempt whose order is still Pending
            // - previously this early-returned on Paid and the order stayed
            // stranded forever, invisible to every sweeper. Only skip when
            // the money has actually landed downstream.
            $alreadyPaid = $attempt->payment_status === PaymentStatus::Paid;

            if ($alreadyPaid && $this->settlementLanded($attempt)) {
                return;
            }

            // Already-Paid attempts were verified by whoever stamped them
            // (gateway verify or webhook signature); re-enter the chain
            // without a second gateway round-trip.
            $provider = $alreadyPaid ? null : $gatewayFactory->getProvider($attempt->gateway);
            $isPaid = $alreadyPaid || $provider->verifyPayment($attempt);

            if ($isPaid) {
                // If this is a WalletFunding attempt, process it via WalletFundingService
                if ($attempt->payable_type === WalletFunding::class) {
                    Log::info("VerifyPaymentJob: payment confirmed for WalletFunding attempt {$attempt->id}");
                    $funding = $attempt->payable;
                    if ($funding && $funding->status !== FundingStatus::Completed) {
                        $fundingService = app(WalletFundingService::class);
                        $fundingService->processSuccessfulFunding(
                            $funding->reference,
                            $attempt->gateway_reference ?: 'VERIFY-'.uniqid(),
                            $attempt->verification_payload ?: []
                        );
                    }

                    return;
                }

                // Check if the order is already Paid by a different attempt
                $order = Order::where('id', $attempt->order_id)->lockForUpdate()->first();
                if ($order && $order->payment_status === PaymentStatus::Paid) {
                    Log::warning("VerifyPaymentJob: Duplicate payment detected. Order {$order->id} is already Paid. Dispatching refund for attempt {$attempt->id}");
                    $attempt->payment_status = PaymentStatus::Paid;
                    $attempt->save();

                    // Queued only once the surrounding transaction commits: a
                    // rollback must not leave a refund in flight for an
                    // attempt whose Paid stamp just evaporated.
                    DB::afterCommit(fn () => RefundPaymentJob::dispatch($attempt));

                    return;
                }

                Log::info("VerifyPaymentJob: payment confirmed for order attempt {$attempt->id}");

                // Coins (or any late payment) landing after our sweeper closed
                // the order. The customer paid the quoted amount and the
                // gateway has it, so the order is settled like any other late
                // payment; but an order coming back from Cancelled is worth a
                // human's glance, so it is stamped and ops are told.
                $lateArrival = $order !== null && (
                    $order->order_status === OrderStatus::Cancelled
                    || $order->payment_status === PaymentStatus::Failed
                );

                if ($lateArrival) {
                    $attempt->metadata = array_merge($attempt->metadata ?? [], [
                        'crypto' => array_merge((array) data_get($attempt->metadata, 'crypto', []), [
                            'late_arrival' => [
                                'previous_order_status' => $order->order_status?->value,
                                'previous_payment_status' => $order->payment_status?->value,
                                'detected_at' => now()->toIso8601String(),
                            ],
                        ]),
                    ]);
                    $attempt->save();
                }

                // 1. Transition the order's payment status to Paid
                if ($attempt->order) {
                    $orderService->transitionPaymentStatus($attempt->order, PaymentStatus::Paid, $attempt->verification_payload);
                }

                // 2. Synchronize active PaymentSession model if exists
                $attempt->load('paymentSession');
                if ($attempt->paymentSession) {
                    $sessionService = app(PaymentSessionService::class);
                    $sessionService->confirmSession($attempt->paymentSession, [
                        'transaction_id' => $attempt->gateway_reference,
                        'payload' => $attempt->verification_payload,
                    ]);
                }

                // 3 + 4. Confirmation event and fulfillment, deferred to
                // commit. The queue connections run after_commit=false, so a
                // bare dispatch here leaves Redis with fulfillment jobs for
                // an order whose Paid transition can still roll back - a
                // worker could be buying vouchers for an unpaid order. Same
                // pattern confirmSession already uses for its events.
                $order = Order::find($attempt->order_id);
                if ($order) {
                    DB::afterCommit(function () use ($order, $attempt, $lateArrival) {
                        PaymentConfirmed::dispatch($order, $attempt);

                        // Staggered: identical offers are spaced out so
                        // duplicate purchases don't trip supplier velocity
                        // protection (Zendit error -29).
                        FulfillOrderItemJob::dispatchStaggered($order->items);

                        if ($lateArrival) {
                            $this->alertLateArrival($order, $attempt);
                        }
                    });
                }

                // 5. Update any pending webhooks related to this attempt
                if ($attempt->idempotency_key) {
                    PaymentWebhook::where('reference', $attempt->idempotency_key)
                        ->where('processing_status', 'pending')
                        ->update([
                            'processed' => true,
                            'processing_status' => 'processed',
                            'processed_at' => now(),
                        ]);
                }
            } else {
                Log::warning("VerifyPaymentJob: payment verification returned unpaid/pending for attempt {$attempt->id}");

                if ($provider !== null) {
                    $this->recordGatewayRefusal($attempt, $provider, $orderService);
                }
            }
        });
    }

    /**
     * The gateway said no. A refused mobile money prompt (no balance, over
     * the daily limit, declined on the handset) used to leave the session
     * open: the verification returned false, nothing failed the session, and
     * the customer watched a spinner until the sweeper expired it half an
     * hour later with no reason at all. The verdict is graded and the session
     * failed with a sentence the customer can act on; the operator's own
     * words stay on the session for the admin page and the classifier.
     */
    private function recordGatewayRefusal(PaymentAttempt $attempt, PaymentProviderInterface $provider, OrderService $orderService): void
    {
        if (! $provider instanceof FlutterwavePaymentProvider) {
            return;
        }

        $attempt->refresh();
        $verdict = $provider->terminalFailure($attempt);

        if ($verdict === null) {
            return;
        }

        $attempt->load('paymentSession');
        $session = $attempt->paymentSession;

        if ($session === null || ! in_array($session->status, self::SINGLE_CHARGE_SESSION_STATUSES, true)) {
            return;
        }

        $failure = GatewayFailure::fromFlutterwave(['data' => ['status' => 'failed', 'processor_response' => $verdict['processor_response']]]);
        $mobileMoney = $session->status === 'awaiting_confirmation';
        $classification = app(FailureReasonClassifier::class)->classify(
            'flutterwave',
            (string) ($verdict['processor_response'] ?? 'declined'),
            $session->status,
            $failure,
        );
        $reason = CustomerFailureCopy::forVerdict($classification, $mobileMoney);
        $payload = [
            'provider' => 'flutterwave',
            'failure' => $failure,
            'gateway_verdict' => $verdict,
            'client_reason' => 'gateway_refused',
        ];

        Log::info("VerifyPaymentJob: gateway refused attempt {$attempt->id}; session {$session->id} failed with the verdict", [
            'processor_response' => $verdict['processor_response'],
            'reason_code' => $classification['code'],
        ]);

        if ($attempt->payable_type === WalletFunding::class) {
            $funding = $attempt->payable;

            // failFunding fails the attempt and the session as well.
            if ($funding && ! in_array($funding->status, [FundingStatus::Completed, FundingStatus::Failed, FundingStatus::Cancelled], true)) {
                app(WalletFundingService::class)->failFunding($funding, $reason, $payload);
            }

            return;
        }

        app(PaymentSessionService::class)->failSession($session, $reason, $payload);

        $attempt->forceFill(['payment_status' => PaymentStatus::Failed, 'failed_at' => now()])->save();

        $order = $attempt->order;
        if ($order && in_array($order->payment_status, [PaymentStatus::Unpaid, PaymentStatus::Pending], true)) {
            $orderService->transitionPaymentStatus($order, PaymentStatus::Failed, $payload);
        }
    }

    /**
     * Ops hear about every order that came back from Cancelled because its
     * payment landed late: the money is real and the order is now in flight,
     * but a price quote that old, or a customer who has since bought the same
     * thing again, deserves a look. Sev2, one alert per attempt.
     */
    private function alertLateArrival(Order $order, PaymentAttempt $attempt): void
    {
        try {
            app(OperationalAlerter::class)->send(
                severity: AlertSeverity::Sev2,
                key: 'crypto:late-arrival:'.$attempt->id,
                title: 'Payment landed after the order was cancelled',
                message: "Order {$order->order_number} was cancelled by the expiry sweep, then its {$attempt->gateway} payment arrived. The order is settled and sent for fulfilment; check the customer has not bought it again.",
                context: [
                    'Order' => $order->order_number,
                    'Customer id' => $order->user_id,
                    'Gateway' => $attempt->gateway,
                    'Amount' => number_format((float) $attempt->amount, 2).' '.strtoupper((string) $attempt->currency),
                    'Attempt' => $attempt->id,
                    'Admin' => route('admin.transaction', $attempt),
                ],
                runbook: 'docs/runbooks/payment-provider-failure.md',
            );
        } catch (\Throwable $e) {
            Log::error('Late-arrival alert failed', ['attempt_id' => $attempt->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A crypto attempt marked Failed by our own expiry sweep, not by the
     * gateway. The gateway's last known word is kept on the attempt
     * (crypto.status); only failed / expired / refunded / cancelled from the
     * gateway are final. Used to be scoped to wallet fundings, which left an
     * order's coins landing against a dead attempt with nothing moving and
     * nobody told; an order settles the same way, and ops are alerted above.
     */
    private function failedLocallyWhileGatewayStillOpen(PaymentAttempt $attempt): bool
    {
        if (! in_array($attempt->gateway, ['nowpayments', 'crypto'], true)) {
            return false;
        }

        $gatewayStatus = NowPaymentsStatus::normalize(data_get($attempt->metadata, 'crypto.status'));

        return ! NowPaymentsStatus::isFailed($gatewayStatus)
            && ! NowPaymentsStatus::isExpired($gatewayStatus)
            && ! NowPaymentsStatus::isRefunded($gatewayStatus);
    }

    /**
     * Has the money this attempt collected actually reached its destination?
     * For a wallet funding that means the funding completed; for an order it
     * means the order itself records the payment. A Paid attempt failing this
     * check is a stranded settlement the chain above must finish.
     */
    private function settlementLanded(PaymentAttempt $attempt): bool
    {
        if ($attempt->payable_type === WalletFunding::class) {
            return $attempt->payable?->status === FundingStatus::Completed;
        }

        if ($attempt->order) {
            return in_array($attempt->order->payment_status, [
                PaymentStatus::Paid,
                PaymentStatus::Refunded,
                PaymentStatus::PartiallyRefunded,
            ], true);
        }

        return true;
    }
}
