<?php

namespace App\Domain\Security\Services;

use App\Domain\Security\Events\NewDeviceDetected;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

class DeviceTrustManager
{
    public function __construct(private IpLocator $locator) {}

    /**
     * Compute a hybrid fingerprint combining client-provided data and server-side headers.
     */
    public function computeFingerprint(string $installationId, string $userAgent, string $ipAddress): string
    {
        return hash('sha256', $installationId.'|'.$userAgent.'|'.$ipAddress);
    }

    /**
     * Registers a new device or updates an existing one.
     */
    public function registerOrUpdateDevice(User $user, array $deviceData, string $ipAddress, string $userAgent): TrustedDevice
    {
        $installationId = $deviceData['installation_id'] ?? Str::uuid()->toString();
        $fingerprint = $this->computeFingerprint($installationId, $userAgent, $ipAddress);

        $device = TrustedDevice::where('user_id', $user->id)
            ->where('device_uuid', $installationId)
            ->first();

        if (! $device) {
            $device = new TrustedDevice([
                'user_id' => $user->id,
                'device_uuid' => $installationId,
                'trust_score' => 20, // Default for new devices
            ]);
        }

        $device->fingerprint = $fingerprint;

        $agent = new Agent;
        $agent->setUserAgent($userAgent);

        // Only resolve when there is something to learn: the same device
        // signing in from the same address as last time already has its
        // location on the row. This used to be an unconditional lookup on every
        // single login, which put a location call on the authentication critical
        // path for no new information.
        $location = ($device->ip_address !== $ipAddress || $device->country_code === null)
            ? $this->locator->locate($ipAddress)
            : null;

        $device->fill([
            'device_name' => $deviceData['device_name'] ?? $device->device_name ?? self::friendlyDeviceName($agent),
            'browser' => $deviceData['browser'] ?? ($agent->browser() ?: null) ?? $device->browser,
            'os' => $deviceData['os'] ?? ($agent->platform() ?: null) ?? $device->os,
            'device_type' => $deviceData['device_type'] ?? ($agent->isDesktop() ? 'desktop' : ($agent->isTablet() ? 'tablet' : 'mobile')) ?? $device->device_type,
            'ip_address' => $ipAddress,
            'city' => $deviceData['city'] ?? $location?->city ?? $device->city,
            'country' => $deviceData['country'] ?? $location?->countryName ?? $device->country,
            'country_code' => $location?->countryCode ?? $device->country_code,
            'timezone' => $deviceData['timezone'] ?? $location?->timezone ?? $device->timezone,
            'user_agent' => $userAgent,
            'last_seen_at' => now(),
        ]);

        $device->save();

        return $device;
    }

    /**
     * A customer-recognisable device name derived from the user agent, e.g.
     * "iPhone", "Samsung (Android)", "Windows PC", "Mac". Best obtainable
     * truth: user agents identify the device FAMILY, not the retail model -
     * every iPhone reports just "iPhone" (Apple strips the model), and modern
     * Chrome on Android often reduces the model to a privacy placeholder.
     * Null when the agent reveals nothing usable, so callers can keep their
     * own fallback.
     */
    public static function friendlyDeviceName(Agent $agent): ?string
    {
        $model = trim((string) $agent->device());
        $platform = strtolower((string) $agent->platform());

        // Placeholders that name the engine or hide the device: Chrome on
        // Android sends the literal model "K" under reduced user agents.
        if (in_array($model, ['K', 'WebKit', 'Macintosh'], true)) {
            $model = '';
        }

        // Android agents carry the retail model between the OS version and
        // the closing paren ("Linux; Android 13; SM-G991B") and read it more
        // reliably than the Agent library's device table, which misses most
        // Samsung/Xiaomi model codes.
        if (str_contains($platform, 'android')
            && preg_match('/Android [\d.]+; ([^);\/]+)/', (string) $agent->getUserAgent(), $match)) {
            $candidate = trim($match[1]);

            if (! in_array($candidate, ['K', 'U'], true)) {
                $model = $candidate;
            }
        }

        if ($model !== '') {
            $isAndroid = str_contains($platform, 'android');
            $isAppleMobile = in_array(strtolower($model), ['iphone', 'ipad', 'ipod'], true);

            return $isAndroid && ! $isAppleMobile ? "{$model} (Android)" : $model;
        }

        return match (true) {
            str_contains($platform, 'ios') => 'iPhone or iPad',
            str_contains($platform, 'android') => 'Android device',
            str_contains($platform, 'windows') => 'Windows PC',
            str_contains($platform, 'os x'), str_contains($platform, 'mac') => 'Mac',
            str_contains($platform, 'ubuntu'), str_contains($platform, 'linux') => 'Linux PC',
            default => null,
        };
    }

    /**
     * The device the CURRENT request is coming from, or null when it cannot
     * be resolved (legacy session predating device tracking, flag off).
     * Resolution mirrors the security centre: the session overlay is the
     * source of truth, the long-lived device cookie is the first fallback,
     * and the ip + user agent pair is the last resort.
     */
    public function currentDeviceFor(User $user, Request $request): ?TrustedDevice
    {
        $session = UserSession::where('laravel_session_id', session()->getId())->first();

        if ($session && $session->trusted_device_id) {
            $device = TrustedDevice::find($session->trusted_device_id);

            if ($device && $device->user_id === $user->id) {
                return $device;
            }
        }

        $installationId = $request->header('X-Installation-ID', $request->cookie('device_uuid'));

        if ($installationId) {
            $device = TrustedDevice::where('user_id', $user->id)
                ->where('device_uuid', $installationId)
                ->first();

            if ($device) {
                return $device;
            }
        }

        return TrustedDevice::where('user_id', $user->id)
            ->where('ip_address', $request->ip())
            ->where('user_agent', (string) $request->userAgent())
            ->orderByDesc('last_seen_at')
            ->first();
    }

    /**
     * A device whose sign-in code was just entered. A device that is already
     * trusted keeps the trust it has earned: it was asked for a code because
     * the sign-in looked unusual (a country change, or a customer who wants a
     * code every time), not because the device is new. Trusting it again would
     * restart the 24-hour hold on sensitive actions and email the customer about
     * a "new" device they have been using for months.
     */
    public function trustAfterVerification(TrustedDevice $device): void
    {
        if ($device->isTrusted()) {
            return;
        }

        $this->markAsTrusted($device);

        // Now that it's verified, send the "New device logged in" email.
        // NewDeviceDetected goes out through CommunicationManager from the
        // security@ mailbox.
        NewDeviceDetected::dispatch($device);
    }

    public function markAsTrusted(TrustedDevice $device): void
    {
        $device->trusted_since = now();
        $device->trust_score = 40; // Initial bump upon verification
        $device->save();
    }

    public function revokeDevice(TrustedDevice $device): void
    {
        $device->trusted_since = null;
        $device->trust_score = 0;
        $device->save();
    }
}
