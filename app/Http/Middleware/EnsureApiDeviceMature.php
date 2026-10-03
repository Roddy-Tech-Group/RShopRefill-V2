<?php

namespace App\Http\Middleware;

use App\Domain\Security\Services\SecurityPolicyService;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\ApiV2Error;
use App\Support\FeatureFlag;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 24-hour hold on new devices, for the mobile API.
 *
 * The website's EnsureDeviceMature works out which device is calling from the
 * session, then the installation header, then an IP and user-agent match, and
 * when none of those finds a row it lets the request through. That last step
 * was written so a browser session predating device tracking would not be
 * bricked at rollout. On a token API it is a hole: a caller who simply omits
 * X-Installation-ID and varies their user agent resolves to no device, and the
 * hold on changing the PIN or relaxing the sign-in code evaporates.
 *
 * So on /v2 the device is identified ONE way, by the installation id the phone
 * reports, and the hold FAILS CLOSED. Every /v2 token was issued through a
 * sign-in that registered that installation, so a signed-in app always has a
 * row to be found by; a request that cannot name its device, or names one this
 * account has never signed in from, is refused rather than waved through.
 *
 * Honours the same two kill switches as the web: device management off means
 * no device regime at all, and device restriction off means the hold itself is
 * suspended (SecurityPolicyService).
 */
class EnsureApiDeviceMature
{
    public function __construct(private SecurityPolicyService $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->refusalFor($request) ?? $next($request);
    }

    /**
     * The refusal this request earns under the hold, or null when it may proceed.
     * Public so a controller that applies the hold conditionally (a Google-only
     * account revoking a device) uses exactly this rule rather than a copy.
     */
    public function refusalFor(Request $request): ?JsonResponse
    {
        if (FeatureFlag::off('device_management')) {
            return null;
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $installationId = trim((string) $request->header('X-Installation-ID', ''));

        if (preg_match(RequireInstallationId::PATTERN, $installationId) !== 1) {
            return ApiV2Error::make(
                'installation_id_required',
                'This app build cannot perform this action. Please update the app.',
                400,
            );
        }

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('device_uuid', $installationId)
            ->first();

        if ($device === null || ! $this->policy->isDeviceMature($device)) {
            $hours = (int) config('security.device_restriction_hours', 24);

            return ApiV2Error::make(
                'device_restricted',
                "For your security, this action is restricted on new devices for the first {$hours} hours.",
                403,
                ['device_restricted' => true],
            );
        }

        return null;
    }
}
