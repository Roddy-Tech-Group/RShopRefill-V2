<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capture a `?ref=<code>` query param into a 90-day cookie so the referral
 * survives the customer leaving and coming back later to sign up. We respect
 * an existing cookie (don't overwrite - earlier referrer wins) and never
 * touch the cookie when no ref param is present, so this middleware is a
 * no-op on the vast majority of requests.
 *
 * The signup Volt component (resources/views/livewire/auth/register.blade.php)
 * reads the cookie after User::create and creates a Referral row if it can
 * match the code back to a User::referral_code.
 */
class CaptureReferralCookie
{
    public const COOKIE_NAME = 'rshop_ref';

    public const TTL_MINUTES = 60 * 24 * 90; // 90 days

    public function handle(Request $request, Closure $next): Response
    {
        $code = trim($request->queryString('ref', ''));

        $response = $next($request);

        // Capture only when (a) param present, (b) not blank, (c) no prior
        // cookie already set. First-touch attribution.
        if ($code !== '' && ! $request->hasCookie(self::COOKIE_NAME)) {
            // Restrict to URL-safe chars matching the user.referral_code regex
            // in the ReferralCode Volt component - anything else is junk.
            if (preg_match('/^[A-Za-z0-9_-]{3,24}$/', $code) === 1) {
                $response->headers->setCookie(
                    cookie(self::COOKIE_NAME, $code, self::TTL_MINUTES)
                );
            }
        }

        return $response;
    }
}
