<?php

namespace App\Domain\Kyc\Providers;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\DTOs\VerifiedIdentity;
use App\Domain\Kyc\Enums\KycTier;
use App\Domain\Kyc\Exceptions\KycAlreadyEnrolledException;
use App\Domain\Kyc\Exceptions\KycProviderUnavailableException;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SmileIdentity\Signature;
use SmileIdentity\SmileIdentityCore;

/**
 * Smile ID identity verification via the official PHP SDK.
 *
 * Uses the Hosted Web Integration: startVerification() mints a short-lived web
 * token the frontend launches Smile's flow with, and the final result arrives
 * asynchronously at our callback URL, which verdictFromWebhook() authenticates
 * and normalises. Product is chosen by country: Biometric KYC across Smile's
 * African government-database coverage (proves the ID is real AND the person is
 * its owner), Global Document Verification for the rest of the world.
 *
 * Credentials come from config/services.php (env SMILE_*). No live call is made
 * until a Tier 2 step-up actually fires, which only happens once enforcement is
 * switched on, so the integration is dormant-but-ready in shadow mode.
 */
class SmileKycProvider implements KycProvider
{
    /**
     * Smile's numeric job_type for each web-integration product we run.
     */
    private const PRODUCTS_BY_JOB_TYPE = [
        '1' => 'biometric_kyc',
        '6' => 'doc_verification',
    ];

    /**
     * Smile's refusal code for a web token requested for a user it already
     * holds an enrollment for ("Wrong job type. This user is already
     * enrolled."), seen in sandbox 2026-09-15.
     */
    private const ALREADY_ENROLLED_CODE = '2209';

    /**
     * How many of a customer's recent attempts to read back from Smile when
     * looking for the pass behind an existing enrollment.
     */
    private const ENROLLMENT_LOOKBACK = 5;

    public function key(): string
    {
        return 'smile';
    }

    public function startVerification(User $user, ?string $issuingCountry = null): KycSession
    {
        $userId = 'user_'.$user->getKey();
        $jobId = 'kyc_'.$user->getKey().'_'.Str::lower(Str::random(12));
        // The customer's explicit ID-issuing-country choice wins over their
        // profile country: a Nigerian abroad may hold only a foreign passport,
        // and a foreigner in Nigeria may hold a NIN.
        $product = $this->productForCountry($issuingCountry ?? $user->country);

        $token = $this->mintWebToken($userId, $jobId, $product);

        // A new session replaces any earlier attempt still waiting on a
        // verdict, which is almost always a capture the customer closed
        // without submitting. Left pending it would sit there forever with
        // nothing to resolve it. Marked abandoned rather than decided: if it
        // was in fact submitted, its late verdict still lands on it.
        KycVerification::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $this->key())
            ->where('status', 'pending')
            ->update(['status' => 'abandoned']);

        // Persist the attempt so the admin page can show it and a stuck job can
        // be reconciled via queryJobStatus(); the webhook resolves it later.
        KycVerification::create([
            'user_id' => $user->getKey(),
            'provider' => $this->key(),
            'reference' => $jobId,
            'product' => $product,
            'status' => 'pending',
        ]);

        return new KycSession(
            reference: $jobId,
            token: $token,
            metadata: [
                'partner_id' => (string) config('services.smileid.partner_id'),
                'product' => $product,
                'user_id' => $userId,
                'environment' => ((int) config('services.smileid.server')) === 1 ? 'production' : 'sandbox',
                'callback_url' => (string) config('services.smileid.callback_url'),
            ],
        );
    }

    public function verdictFromWebhook(array $payload): ?KycVerdict
    {
        $timestamp = $payload['timestamp'] ?? null;
        $signature = $payload['signature'] ?? null;

        // Reject anything we cannot cryptographically attribute to Smile.
        if (! is_string($timestamp) || ! is_string($signature) || ! $this->signature()->confirm_signature($timestamp, $signature)) {
            return null;
        }

        $partnerParams = $payload['PartnerParams'] ?? $payload['partner_params'] ?? [];
        $reference = is_array($partnerParams) ? ($partnerParams['job_id'] ?? null) : null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $resultCode = (string) ($payload['ResultCode'] ?? $payload['result_code'] ?? '');

        if ($this->isApproved($resultCode, $this->productForJob($reference, $partnerParams))) {
            return KycVerdict::approved($reference, KycTier::IdentityVerified, $payload, VerifiedIdentity::fromSmile($payload));
        }

        $reason = $payload['ResultText'] ?? $payload['result_text'] ?? null;

        return KycVerdict::rejected($reference, is_string($reason) ? $reason : null, $payload);
    }

    /**
     * Ask Smile's job-status API where a verification attempt stands. The SDK
     * verifies the response signature and throws when it cannot. Used by the
     * admin "check with provider" reconciliation for jobs whose webhook has not
     * arrived (or was lost).
     *
     * @return array<string, mixed>
     */
    public function queryJobStatus(KycVerification $verification): array
    {
        return $this->core()->query_job_status(
            ['user_id' => 'user_'.$verification->user_id, 'job_id' => $verification->reference],
            ['return_image_links' => false, 'return_history' => false],
        );
    }

    /**
     * Normalise a job-status response into a verdict, exactly as
     * verdictFromWebhook() does for callbacks. Null while the job is still
     * processing at Smile (job_complete false), so an admin sync on an
     * unfinished job changes nothing.
     *
     * @param  array<string, mixed>  $status
     */
    public function verdictFromJobStatus(array $status): ?KycVerdict
    {
        if (! (bool) ($status['job_complete'] ?? false)) {
            return null;
        }

        $result = (array) ($status['result'] ?? []);
        $reference = data_get($result, 'PartnerParams.job_id');

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $resultCode = (string) data_get($result, 'ResultCode', data_get($result, 'result_code', ''));

        if ($this->isApproved($resultCode, $this->productForJob($reference, (array) data_get($result, 'PartnerParams', [])))) {
            return KycVerdict::approved($reference, KycTier::IdentityVerified, $result, VerifiedIdentity::fromSmile($result));
        }

        $reason = data_get($result, 'ResultText', data_get($result, 'result_text'));

        return KycVerdict::rejected($reference, is_string($reason) ? $reason : null, $result);
    }

    /**
     * The Smile web-integration product for a country: Biometric KYC where Smile
     * has government-database coverage (config-driven list), Global Document
     * Verification everywhere else (Cameroon, most of Central Africa, and the
     * rest of the world), which works without a national ID authority.
     */
    public function productForCountry(?string $iso): string
    {
        $biometric = array_map('strtoupper', (array) config('services.smileid.biometric_countries', []));

        return in_array(strtoupper((string) $iso), $biometric, true)
            ? 'biometric_kyc'
            : 'doc_verification';
    }

    /**
     * Which Smile product a finished job ran. Our own attempt row is the
     * authority; Smile's echoed job_type covers a job minted before attempts
     * were persisted. Null when neither says, which never approves.
     *
     * @param  array<string, mixed>  $partnerParams
     */
    private function productForJob(string $reference, array $partnerParams): ?string
    {
        $recorded = KycVerification::query()->where('reference', $reference)->value('product');

        if (is_string($recorded) && $recorded !== '') {
            return $recorded;
        }

        return self::PRODUCTS_BY_JOB_TYPE[(string) ($partnerParams['job_type'] ?? '')] ?? null;
    }

    /**
     * Whether a Smile ResultCode means identity was verified for the product
     * the job actually ran. Every product reports success with its own code
     * (Document Verification says 0810, not the KYC 1012 this used to accept
     * everywhere, which marked every genuine document pass as rejected), so
     * the accepted codes are looked up per product and an unknown product is
     * never approved.
     */
    private function isApproved(string $resultCode, ?string $product): bool
    {
        if ($resultCode === '' || $product === null) {
            return false;
        }

        $approved = array_map('strval', (array) config("services.smileid.approved_result_codes.{$product}", []));

        return in_array($resultCode, $approved, true);
    }

    /**
     * The live token mint, isolated so tests can stub the only HTTP call in
     * startVerification() while the attempt-recording logic runs for real.
     */
    protected function mintWebToken(string $userId, string $jobId, string $product): ?string
    {
        $response = $this->core()->get_web_token(
            $userId,
            $jobId,
            $product,
            null,
            (string) config('services.smileid.callback_url'),
        );

        return $this->tokenFromResponse($response);
    }

    /**
     * The web token out of Smile's answer, or the refusal as an exception. A
     * refusal used to come back as a session with no token, which the
     * customer saw as a generic "not available" and which left a pending
     * attempt behind that nothing could ever resolve.
     *
     * @throws KycAlreadyEnrolledException when Smile already holds an enrollment for this user
     * @throws KycProviderUnavailableException for any other refusal
     */
    public function tokenFromResponse(mixed $response): string
    {
        $token = is_array($response) ? ($response['token'] ?? null) : null;

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $code = is_array($response) && isset($response['code']) ? (string) $response['code'] : null;
        $message = is_array($response) && isset($response['error']) ? (string) $response['error'] : null;

        throw $code === self::ALREADY_ENROLLED_CODE
            ? new KycAlreadyEnrolledException($code, $message)
            : new KycProviderUnavailableException($code, $message);
    }

    /**
     * Ask Smile for the result of this customer's recent attempts, newest
     * first, and return the first that passed. Used when Smile refuses a new
     * session because the customer is already enrolled: that enrollment came
     * from a pass we either never received or recorded wrongly. Null when no
     * recent attempt reads back as approved.
     */
    public function latestApprovedVerdictFor(User $user): ?KycVerdict
    {
        $attempts = KycVerification::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $this->key())
            ->latest('id')
            ->limit(self::ENROLLMENT_LOOKBACK)
            ->get();

        foreach ($attempts as $attempt) {
            try {
                $verdict = $this->verdictFromJobStatus($this->queryJobStatus($attempt));
            } catch (\Throwable $e) {
                Log::warning('kyc.enrollment_lookup_failed', [
                    'user_id' => $user->getKey(),
                    'reference' => $attempt->reference,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($verdict?->approved) {
                return $verdict;
            }
        }

        return null;
    }

    private function core(): SmileIdentityCore
    {
        return new SmileIdentityCore(
            (string) config('services.smileid.partner_id'),
            (string) config('services.smileid.callback_url'),
            (string) config('services.smileid.api_key'),
            (string) config('services.smileid.server'),
        );
    }

    private function signature(): Signature
    {
        return new Signature(
            (string) config('services.smileid.partner_id'),
            (string) config('services.smileid.api_key'),
        );
    }
}
