<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a brand-new Google sign-up on the completion step until they supply the
 * details the register wizard would have collected: country, phone, gender and
 * their acceptance of the terms.
 *
 * Unlike the email gate, this one is not optional and not deferred to the money
 * actions. Google hands us a name, an email and a picture and nothing else, so
 * an account created from it is genuinely half-registered; letting it browse
 * would leave a customer with no country (which decides their KYC product) and
 * no phone (the one identifier that keeps one line to one account). Signup is
 * simply not finished until this page is submitted.
 *
 * Applied to the whole web group, so there is no route left to slip through.
 * Only {@see User::needsProfileCompletion()} can trigger it, which is false for
 * every account that existed before the step shipped and for every account the
 * register wizard creates.
 */
class EnsureProfileCompleted
{
    /**
     * Paths that must stay reachable while the hold is on, or the hold would
     * trap the customer with no way to satisfy or escape it.
     *
     * `livewire/*` is the important one: the completion form itself submits
     * through Livewire's update endpoint, so redirecting it would make the page
     * unable to save the very data it is asking for. That is not a hole - every
     * component behind it lives on a page this guard already refuses to serve.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'auth/complete-profile',
        'auth/popup-complete',
        'logout',
        'livewire/*',
        // The admin panel authenticates on its own guard; a customer session
        // that happens to ride alongside it must never redirect an operator.
        'admin',
        'admin/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Must be a customer, and the check must come before anything that
        // calls a User method: an admin session resolves $request->user() to an
        // Admin, which knows nothing about profile completion.
        if (! $user instanceof User || $request->is(...self::EXEMPT) || ! $user->needsProfileCompletion()) {
            return $next($request);
        }

        $message = 'Please finish setting up your account to continue.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'profile_incomplete' => true,
            ], 403);
        }

        // Remember where they were heading (checkout, a product, the dashboard)
        // so finishing the form returns them there instead of a generic landing.
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('auth.complete-profile')->with('status', $message);
    }
}
