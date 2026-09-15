<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Services\PreferenceManager;
use App\Domain\PaymentInsights\Enums\IneligibilityReason;
use App\Domain\PaymentInsights\Support\RecoveryCopy;
use App\Models\EmailSuppression;
use App\Models\PaymentOutcome;
use App\Models\PaymentSession;
use App\Models\TransactionRecovery;
use App\Models\User;
use App\Support\FeatureFlag;

/**
 * Decides whether a failed payment outcome should trigger a transaction
 * recovery email. Every "no" carries an IneligibilityReason so analytics can
 * explain why recoveries were skipped, not just that they were.
 */
class RecoveryEligibilityService
{
    /** Failure reasons that must never be invited to retry. */
    private const RISK_REASONS = ['risk_rejected', 'fraud_detected', 'high_risk'];

    /** At most one recovery email per customer per rolling day. */
    private const COOLDOWN_HOURS = 24;

    /** Hard cap across a rolling week so we never nag a serially failing customer. */
    private const MAX_PER_WEEK = 3;

    public function __construct(
        private readonly PreferenceManager $preferences,
        private readonly PaymentBackoffManager $backoff,
    ) {}

    /**
     * @return array{eligible: bool, reason: IneligibilityReason|null}
     */
    public function evaluate(PaymentOutcome $outcome): array
    {
        $session = $outcome->paymentSession;

        if (FeatureFlag::off('payment_insights')) {
            return $this->skip(IneligibilityReason::FeatureDisabled);
        }

        // Paying from the internal wallet balance cannot fail at a provider —
        // there is nothing for the customer to "complete" via email.
        if ($session?->provider === 'wallet') {
            return $this->skip(IneligibilityReason::InternalWalletPayment);
        }

        if (! $session || ! $session->customer_email) {
            return $this->skip(IneligibilityReason::NotApplicable);
        }

        // Risk-rejected payments must not be invited to retry.
        $rawReason = strtolower((string) ($outcome->metadata['raw_reason'] ?? ''));
        foreach (self::RISK_REASONS as $riskReason) {
            if (str_contains($rawReason, $riskReason)) {
                return $this->skip(IneligibilityReason::NotApplicable);
            }
        }

        // Checked before the retryable gate so the analytics say WHY the robot
        // stayed quiet: not "could not succeed" but "was ours to fix". An
        // automated "try again" to someone we just failed is the wrong voice:
        // it implies they can fix it, and on 2026-09-09 it sent a Ghana
        // customer back into the same broken rail. These raise a SEV-2 instead
        // (insights:alert-failures) and a person writes.
        if (in_array((string) $outcome->failure_reason_code, FailureReasonClassifier::PLATFORM_FAULT_CODES, true)) {
            return $this->skip(IneligibilityReason::PlatformFault);
        }

        // Several different cards refused inside two hours is the card-testing
        // shape. Fifty-four recovery emails went to card testers in the first
        // three months because their sessions read as abandonment; the ladder
        // now says when to stay quiet, whatever the last verdict was.
        if ((bool) ($outcome->metadata['suspected_testing'] ?? false)
            || $this->backoff->suspectedTesting(
                $outcome->user_id ? User::find($outcome->user_id) : null,
                $outcome->customer_ip,
                $outcome->device_uuid,
            )) {
            return $this->skip(IneligibilityReason::SuspectedTesting);
        }

        // A swept session never got a final answer from the gateway: the
        // customer may have paid. "Complete your payment" to that person is
        // an invitation to pay twice, so the sequence leaves them alone and
        // the webhook-loss alert brings a human to it instead.
        if (RecoveryCopy::outcomeUncertain($outcome->failure_reason_code)) {
            return $this->skip(IneligibilityReason::OutcomeUncertain);
        }

        // The classifier's verdict: a non-retryable failure (stolen card,
        // expired card, suspected fraud) can never succeed on retry, so a
        // "complete your payment" email is at best noise and at worst an
        // engraved invitation to a fraudster. Null (pre-upgrade rows) passes
        // through: absence of a verdict is not a verdict.
        if ($outcome->retryable === false) {
            return $this->skip(IneligibilityReason::NonRetryableFailure);
        }

        // Guests get analytics but no recovery email — there is no account to
        // authenticate the recovery against.
        $user = $outcome->user_id ? User::find($outcome->user_id) : null;
        if (! $user) {
            return $this->skip(IneligibilityReason::GuestNoAccount);
        }

        // Customer already retried: a later confirmed outcome, or any newer
        // still-live session, means a recovery email would be noise.
        if ($this->customerAlreadyRetried($outcome, $session)) {
            return $this->skip(IneligibilityReason::NotApplicable);
        }

        if (EmailSuppression::where('email', strtolower($session->customer_email))->exists()) {
            return $this->skip(IneligibilityReason::Suppressed);
        }

        if (! $this->preferences->isAllowed($user, CommunicationCategory::PaymentRecovery, CommunicationChannel::Email)) {
            return $this->skip(IneligibilityReason::UserOptedOut);
        }

        $recentRecoveries = TransactionRecovery::where(function ($query) use ($user, $session) {
            $query->where('user_id', $user->id)
                ->orWhere('customer_email', $session->customer_email);
        });

        if ((clone $recentRecoveries)->where('created_at', '>=', now()->subHours(self::COOLDOWN_HOURS))->exists()) {
            return $this->skip(IneligibilityReason::CooldownActive);
        }

        if ((clone $recentRecoveries)->where('created_at', '>=', now()->subDays(7))->count() >= self::MAX_PER_WEEK) {
            return $this->skip(IneligibilityReason::MaxAttemptsReached);
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function customerAlreadyRetried(PaymentOutcome $outcome, PaymentSession $session): bool
    {
        $confirmedSince = PaymentOutcome::where('user_id', $outcome->user_id)
            ->where('product_line', $outcome->product_line)
            ->where('outcome', 'confirmed')
            ->where('created_at', '>=', $outcome->created_at)
            ->exists();

        if ($confirmedSince) {
            return true;
        }

        // >= not >: a retry started within the same clock second as the failed
        // session must still count as a retry.
        return PaymentSession::where('customer_email', $session->customer_email)
            ->where('id', '!=', $session->id)
            ->where('created_at', '>=', $session->created_at)
            ->whereNotIn('status', ['failed', 'expired', 'cancelled'])
            ->exists();
    }

    /**
     * @return array{eligible: bool, reason: IneligibilityReason}
     */
    private function skip(IneligibilityReason $reason): array
    {
        return ['eligible' => false, 'reason' => $reason];
    }
}
