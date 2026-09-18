<?php

namespace App\Domain\Risk\Services;

use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Exceptions\SpendingLimitExceededException;
use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Kyc\Services\SpendingLimitService;
use App\Domain\Risk\DTOs\RiskContext;
use App\Domain\Risk\DTOs\RiskDecision;
use App\Domain\Risk\Enums\RiskOutcome;
use App\Domain\Risk\Exceptions\RiskDeniedException;
use App\Domain\Risk\Exceptions\RiskReviewRequiredException;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The single enforcement seam every money action passes through. It runs the
 * risk engine (which always records an assessment for the audit trail), then,
 * only when risk enforcement is on, turns the decision into behaviour:
 *
 *   allow    -> proceed
 *   step_up  -> force identity verification (Smile) via the KYC gate
 *   review   -> hold the attempt and queue a manual review
 *   deny     -> refuse with a customer-safe message
 *
 * It also honours an existing review's state for the same user+context: a pending
 * review keeps holding, an approved review with a live clearance releases the
 * retry, a fresh rejection keeps refusing.
 *
 * KYC's own value-based step-up still applies independently of risk enforcement,
 * so with BOTH risk flags off this gate behaves exactly like the old KYC gate
 * (value-based step-up only) while additionally recording a shadow assessment.
 *
 * Only the three intentional control-flow exceptions propagate; any unexpected
 * fault in the enforcement logic fails open (logged), so a bug here can never
 * break a checkout or funding.
 */
class RiskGate
{
    public function __construct(
        private readonly RiskEngine $engine,
        private readonly RiskReviewService $reviews,
        private readonly KycGate $kycGate,
        private readonly KycTierService $tiers,
        private readonly SpendingLimitService $spendingLimits,
    ) {}

    /**
     * @throws KycStepUpRequiredException|RiskReviewRequiredException|RiskDeniedException|SpendingLimitExceededException
     */
    public function guard(RiskContext $context): void
    {
        $decision = $this->engine->assess($context);

        // KYC value-based requirement, respecting the KYC enforcement flag on its
        // own. Computed up front so it can layer under any risk outcome.
        $kycRequired = $context->user !== null
            ? $this->tiers->requiredStepUp($context->user, $context->amountUsd, $context->context, bringsNewMoney: $context->bringsNewMoney)
            : null;

        if ($decision->enforced) {
            try {
                $this->enforce($context, $decision);
            } catch (KycStepUpRequiredException|RiskReviewRequiredException|RiskDeniedException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Fail open on an unexpected fault: availability wins, and the
                // assessment is already recorded.
                Log::error('risk.gate_failed', [
                    'context' => $context->context,
                    'user_id' => $context->user?->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Baseline KYC value-based step-up (independent of risk enforcement).
        if ($kycRequired !== null) {
            $this->kycGate->stepUp($context->user, $kycRequired, $context->context);
        }

        // Transaction caps on money in: fundings and gateway-paid orders alike,
        // on the same meter as the cumulative KYC dial. An action that spends
        // balance already held brings nothing in and is never capped. Behind
        // its own enforce flag; shadow logs and passes.
        if ($context->user !== null && ! $context->isGuest()) {
            $this->spendingLimits->guard($context->user, $context->amountUsd, $context->context, $context->bringsNewMoney);
        }
    }

    /**
     * Apply the enforced risk decision, first honouring any governing review.
     */
    private function enforce(RiskContext $context, RiskDecision $decision): void
    {
        $review = $this->reviews->latestFor($context->user, $context->context);

        if ($review !== null) {
            if ($review->status->isBlocking()) {
                throw new RiskReviewRequiredException($review);
            }

            if ($review->hasLiveClearance()) {
                return; // approved and still cleared: let the retry through
            }

            if ($review->isRejectionActive()) {
                throw RiskDeniedException::fromDecision($decision);
            }
            // approved-expired or rejection-expired: fall through and re-decide
        }

        switch ($decision->outcome) {
            case RiskOutcome::Deny:
                throw RiskDeniedException::fromDecision($decision);
            case RiskOutcome::Review:
                // The engine already persisted the assessment; open() dedups per
                // user+context so repeated attempts share one queue entry.
                throw new RiskReviewRequiredException($this->reviews->open($decision, $context));
            case RiskOutcome::StepUp:
                // A step-up hands the customer to the identity provider and
                // bypasses the KYC enforcement flag, so it has its own switch.
                // Review and deny come only from behaviour signals and can be
                // enforced first; the step-up waits until the provider is
                // proven live. Off: log what would have happened, let through.
                if (! FeatureFlag::on('risk_step_up_enforce', false)) {
                    Log::info('risk.shadow_step_up', [
                        'assessment_id' => $decision->assessmentId,
                        'context' => $context->context,
                        'user_id' => $context->user?->getKey(),
                        'score' => $decision->score,
                        'band' => $decision->band->value,
                    ]);

                    return;
                }

                $this->kycGate->stepUp($context->user, KycTier::IdentityVerified, $context->context);

                return;

            case RiskOutcome::Allow:
                return;
        }
    }
}
