<?php

namespace App\Http\Resources\V2;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in customer, as /v2 describes them.
 *
 * Deliberately an ALLOWLIST rather than the model. The app cannot be recalled,
 * so a field that appears here is a field we are committed to; and a resource
 * that leaks whatever happens to be on the model today would publish the next
 * column somebody adds, including internal ones such as suspension reasons or
 * PIN counters.
 *
 * @property User $resource
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'country' => $this->country,
            'gender' => $this->gender,
            // Absolute. An uploaded avatar is stored as a site-relative path
            // ("/storage/avatars/..."), which a browser on our own domain resolves
            // and an app cannot; a Google avatar is already a full URL.
            'avatar_url' => $this->absoluteAvatarUrl(),
            'locale' => $this->locale,
            'display_currency' => $this->display_currency,
            'referral_code' => $this->referral_code,
            'kyc_status' => $this->kyc_status,

            // What the app needs to decide which screen to show next.
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'profile_completed' => $this->profile_completed_at !== null,
            'has_transaction_pin' => $this->transaction_pin !== null,

            // A suspended account can sign in and read, but cannot spend. The
            // app needs to know in order to explain a refusal rather than just
            // show one. The REASON is not published: it is written for our own
            // operators, not for the customer.
            'is_suspended' => $this->suspended_at !== null,

            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    private function absoluteAvatarUrl(): ?string
    {
        $url = $this->avatar_url;

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }
}
