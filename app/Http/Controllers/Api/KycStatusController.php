<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kyc\Exceptions\KycAlreadyEnrolledException;
use App\Domain\Kyc\Exceptions\KycProviderUnavailableException;
use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycProviderFactory;
use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The customer-facing KYC API:
 *
 *  - show(): poll the verification state (used by the Smile modal to wait for
 *    the provider callback, and by the KYC page).
 *  - start(): self-serve entry point. Mints a Smile web session for the
 *    authenticated customer so the KYC page's "Verify now" button can launch
 *    the same hosted flow the money-action step-up uses.
 *  - submitted(): records that the customer finished the capture flow, so the
 *    page shows an honest "in review" state while the provider verdict is in
 *    flight. Only ever moves unsubmitted/rejected forward to pending; never
 *    touches a verified account.
 */
class KycStatusController extends Controller
{
    public function __construct(private readonly KycTierService $tiers) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'kyc_status' => $user->kyc_status,
            'verified' => $user->isKycVerified(),
            'tier' => $this->tiers->currentTier($user)->value,
        ]);
    }

    public function start(Request $request, KycProviderFactory $providers, KycVerdictRecorder $recorder)
    {
        $user = $request->user();

        // Guest shadow accounts have no identity to verify against.
        if ($user->is_guest) {
            return response()->json(['message' => 'Please create an account to verify your identity.'], 403);
        }

        if ($user->isKycVerified()) {
            return response()->json(['message' => 'Your identity is already verified.'], 409);
        }

        if ($user->kyc_status === 'pending') {
            return response()->json(['message' => 'Your verification is already in review. This usually completes within a few minutes.'], 409);
        }

        // Closed until the provider is live in production. Checked before
        // anything reaches the provider, so no session is started that could
        // never end in a verified account.
        if (! KycGate::verificationAvailable()) {
            return response()->json(['message' => 'Verification is currently unavailable, try again later.'], 503);
        }

        // The customer's explicit ID-issuing-country choice (the KYC page shows
        // the full country list) routes the product: biometric where Smile has
        // government-database coverage, document verification everywhere else.
        $validated = $request->validate([
            'country' => ['nullable', 'string', 'size:2', Rule::in(array_values(config('countries.codes', [])))],
        ]);
        $issuingCountry = isset($validated['country']) ? strtoupper($validated['country']) : null;

        $provider = null;

        try {
            $provider = $providers->forUser($user);
            $session = $provider->startVerification($user, $issuingCountry);
        } catch (KycAlreadyEnrolledException $e) {
            // The provider already passed this customer, so it will not run
            // another verification. Read that pass back instead of leaving
            // them at a dead end.
            if ($provider instanceof SmileKycProvider && ($verdict = $provider->latestApprovedVerdictFor($user))) {
                $recorder->apply($verdict, $user, source: 'enrollment_reconcile', correctDecided: true);

                return response()->json([
                    'already_verified' => true,
                    'message' => 'Your identity is already verified.',
                ]);
            }

            Log::error('kyc.already_enrolled_without_a_pass', [
                'user_id' => $user->getKey(),
                'provider_code' => $e->providerCode,
                'provider_message' => $e->providerMessage,
            ]);

            return response()->json(['message' => 'We could not start verification right now. Please contact support.'], 502);
        } catch (KycProviderUnavailableException $e) {
            Log::error('kyc.self_serve_session_refused', [
                'user_id' => $user->getKey(),
                'provider_code' => $e->providerCode,
                'provider_message' => $e->providerMessage,
            ]);

            return response()->json(['message' => 'We could not start verification right now. Please try again in a moment.'], 502);
        } catch (\Throwable $e) {
            Log::error('kyc.self_serve_start_failed', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'We could not start verification right now. Please try again in a moment.'], 502);
        }

        // Same shape as KycStepUpRequiredException::toResponse()['smile'], so
        // window.rshopKyc.launch() works identically from both entry points.
        return response()->json([
            'message' => 'Verification session created.',
            'smile' => [
                'token' => $session->token,
                'reference' => $session->reference,
                'partner_id' => $session->metadata['partner_id'] ?? null,
                'product' => $session->metadata['product'] ?? null,
                'environment' => $session->metadata['environment'] ?? null,
                'callback_url' => $session->metadata['callback_url'] ?? null,
                'user_id' => $session->metadata['user_id'] ?? null,
            ],
        ]);
    }

    public function submitted(Request $request)
    {
        $user = $request->user();

        if ($user->is_guest) {
            return response()->json(['message' => 'Not applicable.'], 403);
        }

        // Forward-only: a verified account is never demoted, and pending stays
        // pending. Everything else (unsubmitted, rejected, null) moves to
        // pending now that the capture has been handed to the provider.
        if (! $user->isKycVerified() && $user->kyc_status !== 'pending') {
            $user->update(['kyc_status' => 'pending']);
        }

        return response()->json(['kyc_status' => $user->fresh()->kyc_status]);
    }
}
