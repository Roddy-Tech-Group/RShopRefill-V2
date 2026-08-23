<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\VerificationResendThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resends the email-verification link on demand (the checkout notice's Resend
 * button). Two server-side brakes, both must clear:
 *
 *  - the escalating schedule (30s -> 1m -> 2m -> 5m -> 10m per send), so
 *    repeated taps wait progressively longer;
 *  - the flat verify-resend budget shared with the middleware auto-send and the
 *    auth modal, the absolute spam ceiling.
 *
 * Every response carries retry_after so the UI can mirror the real countdown.
 */
class ResendVerificationController extends Controller
{
    public function __invoke(Request $request, VerificationResendThrottle $throttle): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['verified' => true, 'message' => 'Your email is already verified.']);
        }

        $result = $throttle->attemptSend($user);

        if (! $result['sent']) {
            return response()->json([
                'message' => $result['reason'] === 'budget'
                    ? 'Too many verification emails requested. Please try again later.'
                    : 'Please wait before requesting another link.',
                'retry_after' => $result['retry_after'],
            ], 429);
        }

        return response()->json([
            'sent' => true,
            'message' => 'A new verification link is on its way to '.$user->email.'.',
            'retry_after' => $result['retry_after'],
        ]);
    }
}
