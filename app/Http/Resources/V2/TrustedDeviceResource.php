<?php

namespace App\Http\Resources\V2;

use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One device that has signed into the account, for the security screen.
 *
 * The point of this screen is that a customer can recognise their own devices and
 * spot one they do not. So it shows what a person can actually identify a device
 * by: a name, the platform, roughly where it was and when it was last used.
 *
 * `fingerprint` and `trust_score` are not published. The fingerprint is a
 * correlation handle we use internally, and the score is our own risk signal:
 * publishing it would tell an attacker how close their device is to being trusted.
 *
 * The full IP address is not published either. The city and country answer "was
 * this me?"; the exact address does not help a customer and is one more piece of
 * personal data on a screen that does not need it.
 *
 * @property TrustedDevice $resource
 */
class TrustedDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->device_name,
            'platform' => $this->os,
            'browser' => $this->browser,
            'type' => $this->device_type,

            'location' => [
                'city' => $this->city,
                'country' => $this->country,
                'country_code' => $this->country_code,
            ],

            'trusted' => $this->trusted_since !== null,
            'trusted_since' => $this->trusted_since?->toIso8601ZuluString(),
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),

            // So the app can mark "this device" in the list and avoid offering to
            // sign the customer out of the phone they are holding without saying so.
            'is_current' => $this->device_uuid !== null
                && $this->device_uuid === $request->header('X-Installation-ID'),
        ];
    }
}
