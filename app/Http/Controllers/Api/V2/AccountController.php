<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Kyc\Services\KycGate;
use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Security\Services\SessionRevocationService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApiDeviceMature;
use App\Http\Requests\Api\V2\UpdateProfileRequest;
use App\Http\Resources\V2\TrustedDeviceResource;
use App\Http\Resources\V2\UserResource;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * The customer's own account: their details, their devices, their identity status.
 *
 * One thing is deliberately NOT here: changing the email address. On the web that
 * flow re-proves the password, notifies the old address and re-verifies the new
 * one, because the email IS the account. A thinner version of it on the app would
 * be the weakest door into every account we have, so it waits until it can be done
 * properly rather than shipping as a convenience.
 */
class AccountController extends Controller
{
    public function __construct(private KycTierService $tiers) {}

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $validated['phone'] = $this->canonicalisePhone(
                $user,
                (string) $validated['phone'],
                $validated['country'] ?? $user->country,
            );
        }

        // A verified phone is a verified NUMBER. Swapping the number, or clearing
        // it, cannot carry the verification across, or the identity tier it earned
        // would survive a change to a number nobody has proved they hold.
        if (array_key_exists('phone', $validated) && $validated['phone'] !== $user->phone) {
            $validated['phone_verified_at'] = null;
        }

        $user->fill($validated)->save();

        return response()->json(['data' => ['user' => (new UserResource($user->fresh()))->resolve()]]);
    }

    /**
     * Every device that has signed into this account.
     *
     * Not paginated: an account has a handful of these, and the whole point of the
     * screen is to scan the list for one you do not recognise. Paging it would hide
     * the very device somebody is looking for.
     */
    public function devices(Request $request): JsonResponse
    {
        $devices = TrustedDevice::where('user_id', $request->user()->id)
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => TrustedDeviceResource::collection($devices)->resolve()]);
    }

    /**
     * Sign a device out and withdraw its trust.
     *
     * The account password is the gate, mirroring the web exactly. Deliberately NOT
     * the 24-hour new-device hold: a customer whose account has just been taken over
     * is signing in from somewhere new themselves, and making them wait a day to cut
     * the thief loose would be precisely the wrong way round.
     *
     * A Google-only account has no password to prove with, so it keeps the hold as
     * the only proof of identity available to it, through the same rule the
     * hold's middleware applies.
     *
     * The proof is demanded BEFORE the device is looked up, so a caller holding a
     * stolen token and no password learns nothing about which device ids exist.
     */
    public function revokeDevice(
        Request $request,
        string $device,
        SessionRevocationService $sessions,
        EnsureApiDeviceMature $hold,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'string', 'current_password']]);
        } elseif ($refusal = $hold->refusalFor($request)) {
            return $refusal;
        }

        $target = TrustedDevice::where('user_id', $user->id)->whereKey($device)->first();

        if ($target === null) {
            // The same answer whether it never existed or belongs to somebody else.
            return ApiV2Error::make('device_not_found', 'That device is no longer on your account.', 404);
        }

        // Revokes its sessions, withdraws trust, emails the owner, and now also
        // kills that phone's API tokens.
        $sessions->revokeDevice($target);

        return response()->json(['data' => ['status' => 'device_signed_out']]);
    }

    /**
     * When the account should demand an emailed code at sign-in.
     *
     * Behind the new-device hold on the route, because relaxing this to "never" is
     * one of the first things an account thief does.
     */
    public function updateOtpPreference(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_otp_preference' => ['required', 'in:never,new_device,always'],
        ]);

        $request->user()->forceFill($validated)->save();

        return response()->json([
            'data' => ['email_otp_preference' => $validated['email_otp_preference']],
        ]);
    }

    /**
     * Identity verification status.
     *
     * Read only. Starting a verification hands a session to a third-party identity
     * provider and is not wired into /v2 yet: the provider is still in sandbox in
     * production, so an endpoint for it would approve everything it was shown.
     */
    public function kyc(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'status' => $user->kyc_status,
                'verified' => $user->isKycVerified(),
                'tier' => $this->tiers->currentTier($user)->value,
                // Whether a verification can be STARTED anywhere right now. False
                // while the provider is not live, so the app does not offer a
                // "Verify" button with nothing behind it.
                'can_start' => KycGate::verificationAvailable(),
            ],
        ]);
    }

    /**
     * One line, one account, checked on the canonical form.
     *
     * A comparison on the national number could not see that 0803... and +234803...
     * are the same line, which is how two accounts end up claiming one number.
     */
    private function canonicalisePhone(User $user, string $phone, ?string $country): string
    {
        $e164 = str_starts_with($phone, '+')
            ? (new PhoneNumber($phone))->formatE164()
            : (new PhoneNumber($phone, [$country]))->formatE164();

        if (User::where('phone', $e164)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'This phone number is already registered to another account.',
            ]);
        }

        return $e164;
    }
}
