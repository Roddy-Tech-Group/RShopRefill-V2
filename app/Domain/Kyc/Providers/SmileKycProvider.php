<?php

namespace App\Domain\Kyc\Providers;

use App\Domain\Kyc\Contracts\KycProvider;
use App\Domain\Kyc\DTOs\KycSession;
use App\Domain\Kyc\DTOs\KycVerdict;
use App\Domain\Kyc\Enums\KycTier;
use App\Models\KycVerification;
use App\Models\User;
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

        if ($this->isApproved($resultCode)) {
            return KycVerdict::approved($reference, KycTier::IdentityVerified, $payload);
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

        if ($this->isApproved($resultCode)) {
            return KycVerdict::approved($reference, KycTier::IdentityVerified, $result);
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
     * Whether a Smile ResultCode means identity was verified. Config-driven so
     * the accepted set can be tuned per product without a deploy; the default is
     * Smile's KYC exact-match code. CONFIRM the full set against Smile's
     * result-code documentation and sandbox before switching enforcement on.
     */
    private function isApproved(string $resultCode): bool
    {
        $approved = array_map('strval', (array) config('services.smileid.approved_result_codes', ['1012']));

        return $resultCode !== '' && in_array($resultCode, $approved, true);
    }

    /**
     * The live token mint, isolated so tests can stub the only HTTP call in
     * startVerification() while the attempt-recording logic runs for real.
     */
    protected function mintWebToken(string $userId, string $jobId, string $product): ?string
    {
        $token = $this->core()->get_web_token(
            $userId,
            $jobId,
            $product,
            null,
            (string) config('services.smileid.callback_url'),
        );

        return is_array($token) ? ($token['token'] ?? null) : null;
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
