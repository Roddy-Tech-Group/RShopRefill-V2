<?php

namespace App\Http\Middleware;

use App\Support\ApiV2Error;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Insists that a /v2 sign-in names the device it is coming from.
 *
 * Every device-trust decision hangs off a STABLE per-installation id. The
 * website gets one from a ten-year cookie. An app has no cookie, so it generates
 * one on first launch and keeps it in storage.
 *
 * Without this check the fallback is to invent an id per request, which would
 * make every single call look like a brand new device: a new trusted_devices row
 * each time, an email code demanded on every login, and the new-device hold on
 * sensitive actions never passing. Better to refuse clearly than to degrade into
 * that quietly.
 */
class RequireInstallationId
{
    /**
     * Printable ASCII with no whitespace, 8 to 191 characters. A UUID is what the
     * app sends and fits comfortably. The value is stored in trusted_devices,
     * api_refresh_tokens and the token name, and keys a rate limiter, so control
     * characters and newlines are refused rather than written.
     */
    public const PATTERN = '/^[\x21-\x7E]{8,191}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $installationId = trim((string) $request->header('X-Installation-ID', ''));

        if (preg_match(self::PATTERN, $installationId) !== 1) {
            return ApiV2Error::make(
                'installation_id_required',
                'This app build cannot sign in. Please update the app.',
                400,
            );
        }

        return $next($request);
    }
}
