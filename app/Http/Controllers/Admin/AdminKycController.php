<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Services\CommunicationManager;
use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-side KYC review. Lives behind the `admin` middleware group, so every
 * action (including streaming private documents) is admin-only.
 */
class AdminKycController extends Controller
{
    /** Document type => the submission column holding its private path. */
    private const DOCUMENTS = [
        'front' => 'document_front_path',
        'back' => 'document_back_path',
        'selfie' => 'selfie_path',
    ];

    /**
     * Stream a KYC document from the private `local` disk (never web-accessible).
     */
    public function document(KycSubmission $submission, string $type): StreamedResponse
    {
        abort_unless(isset(self::DOCUMENTS[$type]), 404);

        $path = $submission->{self::DOCUMENTS[$type]};

        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * Approve a submission: mark it verified and raise the customer's status.
     */
    public function approve(KycSubmission $submission, CommunicationManager $communication): RedirectResponse
    {
        $submission->update([
            'status' => 'approved',
            'rejection_reason' => null,
            'reviewed_by' => auth('admin')->id(),
            'reviewed_at' => now(),
        ]);

        $submission->user->update(['kyc_status' => 'verified']);

        $this->notify(
            $communication,
            $submission,
            'Identity verified',
            'Your identity has been verified. Your account now has raised transaction limits.',
        );

        return back()->with('status', 'Customer identity approved.');
    }

    /**
     * Reject a submission with a reason the customer will see.
     */
    public function reject(Request $request, KycSubmission $submission, CommunicationManager $communication): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $submission->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'reviewed_by' => auth('admin')->id(),
            'reviewed_at' => now(),
        ]);

        $submission->user->update(['kyc_status' => 'rejected']);

        $this->notify(
            $communication,
            $submission,
            'Identity verification needs attention',
            'We could not verify your identity: '.$validated['reason'].' Please resubmit your documents.',
        );

        return back()->with('status', 'Customer identity rejected.');
    }

    /**
     * Notify the customer via the Communication Platform (compliance@).
     * CommunicationManager::send() never throws, so a notification hiccup
     * can never block the review decision (which has already been persisted).
     */
    private function notify(CommunicationManager $communication, KycSubmission $submission, string $title, string $message): void
    {
        $communication->send(
            recipient: $submission->user,
            category: CommunicationCategory::Kyc,
            title: $title,
            message: $message,
            correlationId: 'kyc_review_'.$submission->id,
        );
    }
}
