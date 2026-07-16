<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks write actions (checkout, cart writes, wallet funding, etc.) for any
 * customer whose account is suspended. Unlike ban, suspension keeps the user
 * logged in so they can see the suspension banner + request a review — only
 * the *action* attempted here is refused.
 *
 * Apply selectively to write routes via the `not-suspended` alias; do NOT
 * attach to the global web group, otherwise suspended users can't even view
 * their dashboard to file the review request.
 */
class EnsureAccountNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            $message = 'Your account is currently suspended. You cannot perform this action until an admin reviews it.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'suspension' => [
                        'reason' => $user->suspension_reason,
                        'suspended_at' => $user->suspended_at?->toIso8601String(),
                        'review_requested_at' => $user->suspension_review_requested_at?->toIso8601String(),
                    ],
                ], 403);
            }

            return back()->withErrors(['account' => $message]);
        }
        
        if ($user && $user->isRestricted()) {
            $message = 'Your account is in Protected Mode. Please recover your account to perform financial operations.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'protected_mode' => true,
                    'restricted_at' => $user->restricted_at?->toIso8601String(),
                ], 403);
            }

            return redirect()->route('security.recover');
        }

        return $next($request);
    }
}
