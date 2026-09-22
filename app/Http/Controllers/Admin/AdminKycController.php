<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Http\Controllers\Controller;
use App\Models\KycVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Admin-side KYC. Identity is only ever verified by the provider, so the one
 * action here is reconciling an attempt against the provider's own record.
 * The admin override of a customer's status lives on the customer page
 * (AdminCustomerController::setKycStatus). Lives behind the `admin`
 * middleware group.
 */
class AdminKycController extends Controller
{
    /**
     * Reconcile a pending provider attempt against Smile's job-status API. For
     * the webhook-never-arrived case: if Smile has finished the job, the
     * verdict is applied exactly as the webhook would have; if Smile is still
     * processing, nothing changes.
     */
    public function sync(KycVerification $verification, SmileKycProvider $provider, KycVerdictRecorder $recorder): RedirectResponse
    {
        if (! $verification->isAwaitingVerdict()) {
            return back()->with('status', 'This verification already has a verdict.');
        }

        try {
            $status = $provider->queryJobStatus($verification);
        } catch (\Throwable $e) {
            Log::error('kyc.admin_sync_failed', [
                'verification_id' => $verification->id,
                'reference' => $verification->reference,
                'error' => $e->getMessage(),
            ]);

            return back()->with('status', 'Could not reach Smile ID. Please try again shortly.');
        }

        $verdict = $provider->verdictFromJobStatus($status);

        if (! $verdict) {
            return back()->with('status', 'Smile ID is still processing this verification. Check back shortly.');
        }

        $recorder->apply($verdict, $verification->user, source: 'admin_sync');

        return back()->with('status', $verdict->approved
            ? 'Smile ID approved this verification; the customer is now verified.'
            : 'Smile ID could not verify this customer: '.($verdict->reason ?: 'no reason given.'));
    }
}
