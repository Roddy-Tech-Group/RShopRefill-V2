<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\Payment\Providers\FlutterwavePaymentProvider;
use App\Domain\Payment\Support\MockMode;
use App\Domain\PaymentInsights\Enums\ProductLine;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Domain\PaymentInsights\Support\IssuerCountry;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentDecline;
use App\Models\PaymentSession;
use App\Models\WalletFunding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Asks the gateway what really happened inside a popup session and writes
 * down every card it refused.
 *
 * Flutterwave sends no webhook for a failed inline charge, lets a customer try
 * several cards under one reference, and our verify call only ever looked for
 * "successful". So a declined card left no trace: the session was filed as
 * cancelled when the popup closed or expired when the sweeper came round, the
 * dashboard called it "walked away", the recovery emails invited the card
 * tester back, and the rate limiter's decline counter read zero from launch.
 *
 * This runs when a popup session ends (closed, cancelled, swept) and, on a
 * queue, after one is confirmed on a later card. It is fail-open throughout:
 * a gateway that cannot be asked changes nothing about the checkout.
 */
class RefusedCardRecorder
{
    /** Gateway statuses that mean a charge was attempted and refused. */
    private const REFUSED_STATUSES = ['failed', 'error'];

    /**
     * Fraud-class cards on one reference before a confirmed payment is worth a
     * human's eyes. Two, not three: a tester who lands on the second card looks
     * exactly like a tester who lands on the fourth, and this raises an alert
     * rather than blocking anybody, so the cheap end of the range is the right
     * one while money is still recoverable.
     */
    private const PAID_AFTER_ALERT_CARDS = 2;

    public function __construct(
        private readonly FlutterwavePaymentProvider $flutterwave,
        private readonly FailureReasonClassifier $classifier,
        private readonly PaymentBackoffManager $backoff,
        private readonly OperationalAlerter $alerter,
    ) {}

    /**
     * Pull the gateway's record for this session's reference and store every
     * refused card. Returns all declines now known for the reference, oldest
     * first, or an empty collection when there is nothing to record.
     *
     * @return Collection<int, PaymentDecline>
     */
    public function reconcile(PaymentSession $session): Collection
    {
        if (! $this->canReconcile($session)) {
            return new Collection;
        }

        $attempt = $session->paymentAttempt;
        $txRef = (string) $attempt->idempotency_key;

        try {
            $charges = $this->flutterwave->listChargesByReference($txRef);
        } catch (\Throwable $e) {
            Log::warning('refused_cards.lookup_failed', ['session' => $session->id, 'error' => $e->getMessage()]);

            return new Collection;
        }

        foreach ($charges as $charge) {
            if (! in_array((string) $charge['status'], self::REFUSED_STATUSES, true) || $charge['id'] === null) {
                continue;
            }

            $verdict = $charge['processor_response'] ?: 'Declined';
            $classification = $this->classifier->classify('flutterwave', $verdict, 'processing', [
                'provider_code' => $verdict,
                'provider_message' => $verdict,
                'http_status' => null,
            ]);

            // createOrFirst, not firstOrCreate: popup-close telemetry, the
            // customer's cancel and the sweeper can reconcile the same session
            // at the same moment. firstOrCreate reads, finds nothing in both
            // requests, and the second insert hits the unique index and fails
            // the request. createOrFirst inserts first and falls back to the
            // existing row when the index says it is already there.
            PaymentDecline::createOrFirst(
                ['gateway' => 'flutterwave', 'gateway_transaction_id' => $charge['id']],
                [
                    'user_id' => $attempt->user_id,
                    'payment_session_id' => $session->id,
                    'payment_attempt_id' => $attempt->id,
                    'product_line' => $this->productLine($session)->value,
                    'tx_ref' => $txRef,
                    'payment_method' => $charge['payment_type'] !== null ? Str::limit((string) $charge['payment_type'], 30, '') : null,
                    'card_fingerprint' => DeclineVerdict::fingerprint($charge['card']['first_6digits'], $charge['card']['last_4digits']),
                    'card_brand' => $charge['card']['type'] !== null ? Str::limit((string) $charge['card']['type'], 40, '') : null,
                    'card_issuer_country' => IssuerCountry::normalise($charge['card']['country']),
                    'verdict' => Str::limit($verdict, 250, ''),
                    'verdict_class' => DeclineVerdict::classify($classification['code'], $verdict, $charge['card']['type']),
                    'reason_code' => $classification['code'],
                    'amount' => $charge['amount'],
                    'currency' => $charge['currency'] ?? strtoupper((string) $session->currency),
                    'customer_ip' => $session->customer_ip,
                    'device_uuid' => $session->device_uuid,
                    'declined_at' => $this->parseTime($charge['created_at']),
                ],
            );
        }

        return PaymentDecline::query()
            ->where('gateway', 'flutterwave')
            ->where('tx_ref', $txRef)
            ->orderBy('declined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * What to fail the session with once the gateway has said a card was
     * refused: a customer-safe sentence, the gateway's verdict for the
     * classifier, and the facts the dashboard and recovery rules read.
     *
     * @param  Collection<int, PaymentDecline>  $declines
     * @return array{reason: string, payload: array<string, mixed>}
     */
    public function failureFor(PaymentSession $session, Collection $declines): array
    {
        /** @var PaymentDecline $latest */
        $latest = $declines->sortByDesc(fn (PaymentDecline $row) => $row->declined_at->getTimestamp())->first();

        $counted = $declines->filter(fn (PaymentDecline $row) => DeclineVerdict::weight($row->verdict_class) > 0);
        $distinctCards = $counted->map(fn (PaymentDecline $row) => $row->card_fingerprint ?? '__unknown__')->unique()->count();

        $user = $session->paymentAttempt?->user;
        $suspected = $this->backoff->suspectedTesting($user, $session->customer_ip, $session->device_uuid);

        $reason = $latest->verdict_class === DeclineVerdict::CLASS_FRAUD
            ? 'Your card was declined. Please try a different card or payment method.'
            : 'Your bank declined this payment. Please check with your bank or try another card.';

        return [
            'reason' => $reason,
            'payload' => [
                'provider' => 'flutterwave',
                'failure' => [
                    'provider_code' => $latest->verdict,
                    'provider_message' => $latest->verdict,
                    'http_status' => null,
                ],
                'refused_cards' => $distinctCards,
                'refused_charges' => $declines->count(),
                'suspected_testing' => $suspected,
            ],
        ];
    }

    /**
     * A payment that went through only after several fraud-class refusals on
     * the same reference is the tester who finally found a working card: the
     * chargeback is coming and the wallet balance is still recoverable today.
     *
     * @param  Collection<int, PaymentDecline>  $declines
     */
    public function alertIfPaidAfterRefusals(PaymentSession $session, Collection $declines): bool
    {
        $fraudCards = $declines
            ->filter(fn (PaymentDecline $row) => $row->verdict_class === DeclineVerdict::CLASS_FRAUD)
            ->map(fn (PaymentDecline $row) => $row->card_fingerprint ?? '__unknown__')
            ->unique()
            ->count();

        if ($fraudCards < self::PAID_AFTER_ALERT_CARDS) {
            return false;
        }

        $userId = $session->paymentAttempt?->user_id;
        $context = [
            'user_id' => $userId,
            'session' => $session->id,
            'product_line' => $this->productLine($session)->value,
            'amount' => $session->amount.' '.strtoupper((string) $session->currency),
            'fraud_class_cards' => $fraudCards,
            'refused_charges' => $declines->count(),
        ];

        if ($userId !== null && Route::has('admin.customer')) {
            $context['customer_page'] = route('admin.customer', $userId);
        }

        return $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'backoff:paid-after-refusals:'.$session->id,
            title: 'Payment succeeded after '.$fraudCards.' refused cards',
            message: 'The gateway refused '.$fraudCards.' different cards as fraud on this reference before one went through. This is the card-testing shape that ends in a chargeback. Hold fulfilment or freeze the wallet while the balance is still there, and check the card country against the customer.',
            context: $context,
            runbook: 'docs/runbooks/payment-provider-failure.md',
        );
    }

    /**
     * Only Flutterwave popup sessions have anything to reconcile, and only
     * when there is a real gateway behind the key.
     */
    private function canReconcile(PaymentSession $session): bool
    {
        if ($session->provider !== 'flutterwave') {
            return false;
        }

        $attempt = $session->paymentAttempt;
        if ($attempt === null || $attempt->gateway !== 'flutterwave' || blank($attempt->idempotency_key)) {
            return false;
        }

        $secret = (string) config('services.flutterwave.secret_key');

        return $secret !== '' && ! MockMode::forKey($secret);
    }

    private function productLine(PaymentSession $session): ProductLine
    {
        return $session->paymentAttempt?->payable_type === WalletFunding::class
            ? ProductLine::WalletFunding
            : ProductLine::Checkout;
    }

    /**
     * The gateway's timestamp, moved into the application's timezone before it
     * is stored.
     *
     * Flutterwave reports in UTC ("2026-09-15T09:00:00.000Z") and the app runs
     * on Africa/Douala. Eloquent writes a Carbon's wall-clock time without
     * converting it, so an unconverted UTC instant was stored an hour early:
     * refusals looked an hour old the moment they arrived, their pauses never
     * fired, and fraud cards fell out of the two hour window after one hour.
     * A refusal can also never be in the future, so a gateway clock running
     * ahead is clamped to now rather than stretching a wait.
     */
    private function parseTime(?string $value): Carbon
    {
        if ($value === null || trim($value) === '') {
            return now();
        }

        try {
            $time = Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return now();
        }

        return $time->isFuture() ? now() : $time;
    }
}
