<?php

namespace App\Http\Middleware;

use App\Support\VerificationResendThrottle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the money-in actions (order placement, wallet funding) for a logged-in
 * customer who has not verified their email, and re-sends the verification link
 * so they can act on it immediately.
 *
 * Verification is a soft requirement for browsing by design: this guard is
 * attached ONLY to the sensitive write routes via the `verified-email` alias,
 * never the global web group, so an unverified user can still shop and reach
 * their dashboard. Guests (no authenticated user) pass straight through, so
 * guest checkout is unaffected.
 *
 * The re-send shares one rate-limit budget with the modal's manual resend
 * (verify-resend|{id}) so repeated blocked attempts cannot be turned into an
 * email-spam vector; the block itself always applies even once sending is
 * throttled.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guests and guest shadow accounts (is_guest) are exempt: guest checkout
        // is a separate flow that verifies identity through the account-claim
        // link after fulfilment, not the email-verification gate.
        if (! $user || $user->is_guest || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        // One decision point for the auto-send: attemptSend() applies the
        // escalating cooldown AND the flat spam ceiling, and advances the
        // schedule, so the frontend's Resend countdown starts from the truth.
        $result = app(VerificationResendThrottle::class)->attemptSend($user);
        $sent = $result['sent'];
        $retryAfter = $result['retry_after'];

        $message = $sent
            ? 'Please verify your email to continue. We just sent a new verification link to '.$user->email.'.'
            : 'Please verify your email to continue. Check your inbox for the verification link we sent to '.$user->email.'.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'email_unverified' => true,
                // Where the frontend's Resend countdown starts.
                'resend_retry_after' => max($retryAfter, 30),
            ], 403);
        }

        return back()->withErrors(['email' => $message]);
    }
}
