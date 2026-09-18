<?php

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Services\NewsletterService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class NewsletterApiController extends Controller
{
    public function __construct(
        private readonly NewsletterService $notificationService
    ) {}

    /**
     * Subscribe to the newsletter.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'source' => 'nullable|string|max:50',
        ]);

        $subscriber = $this->notificationService->subscribeNewsletter(
            $validated['email'],
            $validated['source'] ?? 'api'
        );

        // Generate a cryptographically signed unsubscribe URL for verification and security!
        $unsubscribeUrl = URL::signedRoute('newsletter.unsubscribe', [
            'email' => $subscriber->email,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully subscribed to marketing newsletter.',
            'unsubscribe_url' => $unsubscribeUrl,
        ]);
    }

    /**
     * Unsubscribe from the newsletter via signed URL.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        // Enforce Laravel's cryptographic signature validation!
        if (! $request->hasValidSignature()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired unsubscribe signature.',
            ], 403);
        }

        $email = $request->input('email');

        if (empty($email)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email parameter is required.',
            ], 400);
        }

        $this->notificationService->unsubscribeNewsletter($email);

        return response()->json([
            'status' => 'success',
            'message' => 'You have been successfully unsubscribed from RshopRefill newsletter.',
        ]);
    }
}
