<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Smile ID's asynchronous identity-verification result.
 *
 * Authenticity is proven by the body signature (verified inside
 * SmileKycProvider::verdictFromWebhook via the SDK), so this endpoint needs no
 * header-signature middleware. An unverifiable or unrecognised payload is
 * acknowledged with 200 (so Smile stops retrying) but changes nothing. A
 * verified verdict is applied by KycVerdictRecorder: it resolves the attempt
 * row, stamps the outcome, and flips the user's KYC status; the tier engine
 * reads that to place them at the IdentityVerified tier.
 */
class SmileWebhookController extends Controller
{
    public function __construct(
        private readonly SmileKycProvider $provider,
        private readonly KycVerdictRecorder $recorder,
    ) {}

    public function handle(Request $request)
    {
        $payload = $request->all();

        $verdict = $this->provider->verdictFromWebhook($payload);

        if (! $verdict) {
            Log::warning('smile.webhook.unverified', [
                'job_id' => data_get($payload, 'PartnerParams.job_id'),
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Fallback user resolution covers jobs minted before attempts were
        // persisted; the recorder prefers the attempt row's own user.
        $user = $this->recorder->apply($verdict, $this->resolveUser($payload));

        if ($user) {
            // ResultCode + text are logged so a sandbox run can confirm the
            // approved_result_codes set per product (Biometric KYC and Document
            // Verification report success differently).
            Log::info('smile.webhook.processed', [
                'user_id' => $user->id,
                'reference' => $verdict->reference,
                'approved' => $verdict->approved,
                'result_code' => data_get($payload, 'ResultCode', data_get($payload, 'result_code')),
                'result_text' => data_get($payload, 'ResultText', data_get($payload, 'result_text')),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Map the callback back to our user via the user_id we stamped on the job
     * ('user_{id}').
     */
    private function resolveUser(array $payload): ?User
    {
        $userId = data_get($payload, 'PartnerParams.user_id');

        if (! is_string($userId) || ! str_starts_with($userId, 'user_')) {
            return null;
        }

        return User::find((int) substr($userId, 5));
    }
}
