<?php

namespace App\Domain\Kyc\Services;

use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Exceptions\KycAlreadyEnrolledException;
use App\Domain\Kyc\Exceptions\KycProviderUnavailableException;
use App\Domain\Kyc\Exceptions\KycStepUpRequiredException;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The enforcement seam between the tier engine and the money paths.
 *
 * Called at each money action (order placement, wallet funding) with the
 * transaction's USD value. When the customer must step up a tier to proceed, it
 * mints a verification session with the routed provider (Smile) and throws
 * KycStepUpRequiredException, which the choke-point controllers turn into a
 * "kyc_required" response carrying that session.
 *
 * In shadow mode (features.kyc_enforce_enabled off) the tier engine returns no
 * step-up, so guard() is inert: it records what it WOULD demand and lets the
 * action through. No provider call is made until enforcement is switched on.
 */
class KycGate
{
    public function __construct(
        private readonly KycTierService $tiers,
        private readonly KycProviderFactory $providers,
        private readonly KycVerdictRecorder $recorder,
    ) {}

    /**
     * @param  string  $context  'order' | 'funding'
     * @param  KycTier|null  $riskFloor  a floor the risk engine imposes, raising
     *                                   the required tier independently of value
     *
     * @throws KycStepUpRequiredException
     */
    public function guard(User $user, float $amountUsd, string $context, ?KycTier $riskFloor = null): void
    {
        $required = $this->tiers->requiredStepUp($user, $amountUsd, $context, $riskFloor);

        if ($required === null) {
            return;
        }

        $this->stepUp($user, $required, $context);
    }

    /**
     * Force a verification step-up to the given tier regardless of value or the
     * KYC enforcement flag. Used by the risk engine when a decision (e.g. a HIGH
     * band) requires identity verification on its own authority. Mints the
     * provider session and throws so the choke-point controllers hand the
     * customer straight into the flow.
     *
     * No-op when the user already satisfies the tier.
     *
     * @param  string  $context  'order' | 'funding' — carried onto the exception
     *                           so the customer message uses the right noun
     *
     * @throws KycStepUpRequiredException
     */
    public function stepUp(User $user, KycTier $required = KycTier::IdentityVerified, string $context = 'order'): void
    {
        if ($this->tiers->currentTier($user)->satisfies($required)) {
            return;
        }

        // Verification switched off means no customer can complete one, so
        // demanding it would only block the payment. Logged and let through,
        // exactly as a step-up is in shadow mode.
        if (! self::verificationAvailable()) {
            Log::warning('kyc.step_up_skipped_verification_unavailable', [
                'user_id' => $user->getKey(),
                'context' => $context,
                'required_tier' => $required->value,
            ]);

            return;
        }

        // Already submitted and waiting on the provider's verdict. Starting a
        // new session would abandon the one under review and send the customer
        // (and a paid check) round the flow again, so hold the payment and let
        // the modal wait for the verdict instead.
        if ($user->kyc_status === 'pending') {
            throw new KycStepUpRequiredException($required, new KycSession(reference: '', metadata: ['in_review' => true]), $user, $context);
        }

        $provider = $this->providers->forUser($user);

        try {
            $session = $provider->startVerification($user);
        } catch (KycAlreadyEnrolledException $e) {
            // The provider already passed this customer; read that pass back
            // rather than sending them round the flow again.
            if ($provider instanceof SmileKycProvider && ($verdict = $provider->latestApprovedVerdictFor($user))) {
                $this->recorder->apply($verdict, $user, source: 'enrollment_reconcile', correctDecided: true);

                return;
            }

            $session = $this->unavailableSession($user, $e);
        } catch (KycProviderUnavailableException $e) {
            $session = $this->unavailableSession($user, $e);
        }

        throw new KycStepUpRequiredException($required, $session, $user, $context);
    }

    /**
     * Whether customers can verify their identity right now. Off until the
     * provider runs on production credentials with a live callback URL.
     */
    public static function verificationAvailable(): bool
    {
        return FeatureFlag::on('features.kyc_verification_enabled', false);
    }

    /**
     * A session with no token. Enforcement still holds the payment, and the
     * verification modal tells the customer verification is not available
     * right now instead of opening a flow that cannot start.
     */
    private function unavailableSession(User $user, KycProviderUnavailableException $e): KycSession
    {
        Log::error('kyc.step_up_session_refused', [
            'user_id' => $user->getKey(),
            'provider_code' => $e->providerCode,
            'provider_message' => $e->providerMessage,
        ]);

        return new KycSession(reference: '');
    }
}
