<?php

namespace App\Http\Controllers;

use App\Domain\Communication\Services\AdminNotificationService;
use App\Models\KycSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles a customer's KYC document submission. Files are stored on the private
 * `local` disk (storage/app/kyc/{user}); they are never web-accessible and are
 * only served to admins through a gated route (admin review phase).
 */
class KycController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        // A fresh submission is only allowed when the customer has not already
        // submitted (or was rejected and is resubmitting).
        if (in_array($user->kyc_status, ['pending', 'verified'], true)) {
            return back()->with('status', 'Your verification is already '.$user->kyc_status.'.');
        }

        $validated = $request->validate([
            'kyc_full_name' => ['required', 'string', 'max:255'],
            'kyc_dob' => ['required', 'date', 'before:today'],
            'kyc_country' => ['required', 'string', 'max:100'],
            'kyc_document_type' => ['required', 'in:passport,national_id,drivers_license'],
            'kyc_document_number' => ['required', 'string', 'max:100'],
            'kyc_document_front' => ['required', 'image', 'mimes:jpeg,png', 'max:8192'],
            'kyc_document_back' => ['nullable', 'image', 'mimes:jpeg,png', 'max:8192'],
            'kyc_selfie' => ['required', 'image', 'mimes:jpeg,png', 'max:8192'],
        ]);

        $dir = "kyc/{$user->id}";

        $frontPath = $request->file('kyc_document_front')->store($dir, 'local');
        $backPath = $request->hasFile('kyc_document_back')
            ? $request->file('kyc_document_back')->store($dir, 'local')
            : null;
        $selfiePath = $request->file('kyc_selfie')->store($dir, 'local');

        KycSubmission::create([
            'user_id' => $user->id,
            'full_name' => $validated['kyc_full_name'],
            'date_of_birth' => $validated['kyc_dob'],
            'country' => $validated['kyc_country'],
            'document_type' => $validated['kyc_document_type'],
            'document_number' => $validated['kyc_document_number'],
            'document_front_path' => $frontPath,
            'document_back_path' => $backPath,
            'selfie_path' => $selfiePath,
            'status' => 'pending',
        ]);

        $user->update(['kyc_status' => 'pending']);

        // Surface the submission to admins on their dashboard.
        app(AdminNotificationService::class)->push(
            type: 'kyc',
            title: 'New KYC submission',
            message: $user->name.' submitted identity documents for review.',
            url: route('admin.customer', $user),
        );

        return back()->with('status', 'Your documents were submitted. Verification usually takes up to 48 hours.');
    }
}
