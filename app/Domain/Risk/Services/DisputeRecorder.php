<?php

namespace App\Domain\Risk\Services;

use App\Domain\Risk\DTOs\DisputeNotice;
use App\Domain\Risk\Enums\DisputeStatus;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\RiskLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a gateway's dispute notice becomes state, whether it came by
 * webhook or by the hourly chargeback-list sync, so both land identically.
 *
 * A new dispute is matched to our payment, labels the customer `chargeback`
 * (which the CHARGEBACK_HISTORY and CHARGEBACK_REPEAT risk signals read), and
 * pages with the deadline. A later notice for the same dispute updates the row;
 * reaching won or lost pages once more. A won dispute keeps its label: the
 * customer filed one, which is the signal, and the label's note says it was won.
 *
 * Nothing here answers the dispute at the gateway, moves money, changes an
 * order or restricts the account. A person decides those.
 */
class DisputeRecorder
{
    private const RUNBOOK = 'docs/runbooks/fraud-attack.md';

    public function __construct(private readonly OperationalAlerter $alerter) {}

    public function record(DisputeNotice $notice, string $source): PaymentDispute
    {
        [$dispute, $isNew, $resolvedNow] = DB::transaction(function () use ($notice, $source): array {
            $dispute = PaymentDispute::query()
                ->where('gateway', $notice->gateway)
                ->where('gateway_dispute_id', $notice->disputeId)
                ->lockForUpdate()
                ->first();

            $isNew = $dispute === null;
            $wasOpen = $isNew || $dispute->isOpen();

            if ($isNew) {
                $attempt = $this->matchPayment($notice);

                $dispute = new PaymentDispute([
                    'gateway' => $notice->gateway,
                    'gateway_dispute_id' => $notice->disputeId,
                    'payment_attempt_id' => $attempt?->id,
                    'order_id' => $attempt?->order_id,
                    'user_id' => $attempt?->user_id,
                    'gateway_payment_reference' => $notice->paymentReferences[0] ?? null,
                    'opened_at' => $notice->openedAt ?? now(),
                    'source' => $source,
                ]);
            }

            $dispute->fill([
                'amount' => $notice->amount ?? $dispute->amount,
                'currency' => $notice->currency ?? $dispute->currency ?? $dispute->paymentAttempt?->currency,
                'reason' => $notice->reason ?? $dispute->reason,
                'stage' => $notice->stage ?? $dispute->stage,
                'gateway_status' => $notice->gatewayStatus,
                'due_at' => $notice->dueAt ?? $dispute->due_at,
                'last_payload' => $notice->raw,
            ]);

            // A decided dispute does not reopen on a stale notice arriving late
            // (the sync can read an older list row after the webhook landed).
            if ($wasOpen) {
                $dispute->status = $notice->status;
                $dispute->resolved_at = $notice->status === DisputeStatus::Open ? null : now();
            }

            $dispute->save();

            if ($isNew && $dispute->user_id !== null) {
                RiskLabel::create([
                    'user_id' => $dispute->user_id,
                    'subject_type' => PaymentDispute::class,
                    'subject_id' => (string) $dispute->id,
                    'label' => RiskLabel::LABEL_CHARGEBACK,
                    'source' => $notice->gateway,
                    'note' => sprintf('Dispute %s raised at %s: %s', $notice->disputeId, $dispute->gatewayLabel(), $notice->reason ?? 'no reason given'),
                ]);
            }

            $resolvedNow = $wasOpen && ! $dispute->isOpen();

            if ($resolvedNow && $dispute->status === DisputeStatus::Won) {
                RiskLabel::query()
                    ->where('subject_type', PaymentDispute::class)
                    ->where('subject_id', (string) $dispute->id)
                    ->where('label', RiskLabel::LABEL_CHARGEBACK)
                    ->get()
                    ->each(fn (RiskLabel $label) => $label->update(['note' => $label->note.' (won)']));
            }

            return [$dispute, $isNew, $resolvedNow];
        });

        Log::info('dispute.recorded', [
            'gateway' => $dispute->gateway,
            'dispute' => $dispute->gateway_dispute_id,
            'status' => $dispute->status->value,
            'new' => $isNew,
            'source' => $source,
        ]);

        if ($isNew) {
            $this->alertOpened($dispute);
        } elseif ($resolvedNow) {
            $this->alertResolved($dispute);
        }

        return $dispute;
    }

    /**
     * Page once more for each open dispute whose deadline is under the given
     * number of hours away. Returns how many were paged.
     */
    public function alertApproachingDeadlines(int $hours = 6): int
    {
        $due = PaymentDispute::query()
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '>', now())
            ->where('due_at', '<=', now()->addHours($hours))
            ->whereNull('deadline_alerted_at')
            ->get();

        foreach ($due as $dispute) {
            $this->alerter->send(
                severity: AlertSeverity::Sev1,
                key: "dispute:deadline:{$dispute->id}",
                title: "Dispute deadline in under {$hours} hours: {$dispute->gatewayLabel()} {$dispute->amountLabel()}",
                message: sprintf(
                    'The %s dispute %s is still open and must be answered by %s (%s). If it is not answered it is usually accepted for the customer and the money goes back.',
                    $dispute->gatewayLabel(),
                    $dispute->gateway_dispute_id,
                    $dispute->due_at->format('j M Y, H:i T'),
                    $dispute->due_at->diffForHumans(),
                ),
                context: $this->context($dispute),
                runbook: self::RUNBOOK,
            );

            $dispute->forceFill(['deadline_alerted_at' => now()])->save();
        }

        return $due->count();
    }

    /**
     * Our payment the gateway is talking about. Each gateway names it
     * differently: our own reference (idempotency_key), its charge id (stored
     * as gateway_reference), or for a Flutterwave webhook only its flw_ref,
     * which we hold inside the charge's verification and webhook payloads.
     */
    private function matchPayment(DisputeNotice $notice): ?PaymentAttempt
    {
        $references = $notice->paymentReferences;

        if ($references === []) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('gateway', $notice->gateway)
            ->where(fn ($query) => $query->whereIn('idempotency_key', $references)->orWhereIn('gateway_reference', $references))
            ->latest('created_at')
            ->first();

        if ($attempt !== null || $notice->gateway !== 'flutterwave') {
            return $attempt;
        }

        foreach ($references as $reference) {
            $attempt = PaymentAttempt::query()
                ->where('gateway', 'flutterwave')
                ->where(fn ($query) => $query
                    ->where('verification_payload->data->flw_ref', $reference)
                    ->orWhere('webhook_payload->data->flw_ref', $reference))
                ->latest('created_at')
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }
        }

        return null;
    }

    private function alertOpened(PaymentDispute $dispute): void
    {
        $due = $dispute->due_at
            ? sprintf('Answer by %s (%s).', $dispute->due_at->format('j M Y, H:i T'), $dispute->due_at->diffForHumans())
            : 'The gateway gave no deadline; check the dashboard.';

        $who = $dispute->user_id !== null
            ? "It is on customer #{$dispute->user_id}'s payment and the customer is now labelled chargeback for the risk engine."
            : 'No payment of ours matched the reference the gateway gave ('.($dispute->gateway_payment_reference ?? 'none').'); trace it by hand.';

        $this->alerter->send(
            severity: AlertSeverity::Sev1,
            key: "dispute:opened:{$dispute->id}",
            title: "New {$dispute->gatewayLabel()} dispute: {$dispute->amountLabel()}",
            message: "{$due} {$who} Evidence: the order and transaction reports linked from Admin, Risk, Disputes.",
            context: $this->context($dispute),
            runbook: self::RUNBOOK,
        );
    }

    private function alertResolved(PaymentDispute $dispute): void
    {
        $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: "dispute:resolved:{$dispute->id}",
            title: "{$dispute->gatewayLabel()} dispute {$dispute->status->label()}: {$dispute->amountLabel()}",
            message: $dispute->status === DisputeStatus::Won
                ? "Dispute {$dispute->gateway_dispute_id} was decided in our favour; the money stays with us. The customer's chargeback label stays, marked won."
                : "Dispute {$dispute->gateway_dispute_id} was decided for the customer ({$dispute->gateway_status}); the gateway takes the money back. Check whether the order needs reversing in our books.",
            context: $this->context($dispute),
            runbook: self::RUNBOOK,
        );
    }

    /** @return array<string, mixed> */
    private function context(PaymentDispute $dispute): array
    {
        return array_filter([
            'gateway' => $dispute->gatewayLabel(),
            'dispute' => $dispute->gateway_dispute_id,
            'amount' => $dispute->amountLabel(),
            'reason' => $dispute->reason,
            'gateway status' => $dispute->gateway_status,
            'deadline' => $dispute->due_at?->format('j M Y, H:i T'),
            'customer' => $dispute->user_id ? '#'.$dispute->user_id : null,
            'order' => $dispute->order?->order_number,
            'payment reference' => $dispute->paymentAttempt?->idempotency_key ?? $dispute->gateway_payment_reference,
        ], fn ($value) => $value !== null);
    }
}
