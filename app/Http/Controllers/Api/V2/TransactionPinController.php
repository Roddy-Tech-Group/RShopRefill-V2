<?php

namespace App\Http\Controllers\Api\V2;

use App\Domain\Wallet\Services\TransactionPinService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\ChangeTransactionPinRequest;
use App\Http\Requests\Wallet\ResetTransactionPinRequest;
use App\Http\Requests\Wallet\SetupTransactionPinRequest;
use App\Http\Requests\Wallet\VerifyTransactionPinRequest;
use App\Support\ApiV2Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The four-digit PIN that authorises spending from a wallet.
 *
 * Verifying it returns a short-lived token, and that token is what actually
 * authorises a wallet payment: the app collects the PIN once, exchanges it here, and
 * hands the token to the payment session. The PIN itself never travels with the
 * payment, and the token is claimed atomically when the money moves, so a
 * double-tapped Pay cannot spend twice off one PIN entry.
 *
 * The request classes are the storefront's own, reused rather than restated. The PIN's
 * rules (four digits, confirmed on setup, the old PIN required to change it) are
 * security rules, and a second copy of them is a second place for them to be relaxed.
 *
 * Changing or resetting a PIN sits behind the 24-hour new-device hold, applied on the
 * route. Setting a FIRST one does not: a new customer has to be able to start
 * spending, and there is no existing PIN for a thief to be displacing.
 */
class TransactionPinController extends Controller
{
    public function __construct(private TransactionPinService $pins) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'has_pin' => $user->hasTransactionPin(),
                // Too many wrong guesses locks it. The app needs to say when it opens
                // again rather than letting someone keep trying a locked PIN.
                'is_locked' => $user->isTransactionPinLocked(),
                'locked_until' => $user->transaction_pin_locked_until?->toIso8601ZuluString(),
            ],
        ]);
    }

    public function setup(SetupTransactionPinRequest $request): JsonResponse
    {
        if ($request->user()->hasTransactionPin()) {
            // Setting a PIN over an existing one would be a way around the change
            // flow, which requires the old PIN and sits behind the new-device hold.
            return ApiV2Error::make(
                'transaction_pin_already_set',
                'You already have a transaction PIN. Change it instead.',
                422,
            );
        }

        $this->pins->setupPin($request->user(), (string) $request->input('pin'));

        return response()->json(['data' => ['status' => 'pin_set']], 201);
    }

    /**
     * Exchange the PIN for the short-lived token that authorises a payment.
     *
     * Rate limited on the route. Four digits is ten thousand possibilities, so without
     * a limit this is the weakest thing guarding a wallet.
     */
    public function verify(VerifyTransactionPinRequest $request): JsonResponse
    {
        // Throws ValidationException naming the field when the PIN is wrong or now
        // locked; the envelope renderer carries that message, and the difference
        // matters to whoever is typing.
        $token = $this->pins->verifyPin($request->user(), (string) $request->input('pin'));

        return response()->json([
            'data' => [
                'auth_token' => $token,
                // Seconds, from the service that minted it. The app should ask for
                // the PIN again rather than holding this any longer, and must never
                // store it.
                'expires_in' => TransactionPinService::AUTH_TOKEN_TTL_MINUTES * 60,
            ],
        ]);
    }

    public function change(ChangeTransactionPinRequest $request): JsonResponse
    {
        $this->pins->changePin(
            $request->user(),
            (string) $request->input('old_pin'),
            (string) $request->input('new_pin'),
        );

        return response()->json(['data' => ['status' => 'pin_changed']]);
    }

    /**
     * Start a reset for somebody who has forgotten their PIN. Sends a code to the
     * account's email, which is the only factor they still have.
     */
    public function requestReset(Request $request): JsonResponse
    {
        if (! $request->user()->hasTransactionPin()) {
            return ApiV2Error::make(
                'transaction_pin_not_set',
                'You do not have a transaction PIN to reset.',
                422,
            );
        }

        // The service enforces the password step-up and the hourly limit, so the
        // password is forwarded rather than checked here: one place decides when it
        // is required. Only its SHAPE is checked, so an array cannot reach a string
        // comparison.
        $request->validate(['password' => ['nullable', 'string', 'max:255']]);

        $this->pins->requestReset($request->user(), $request->input('password'));

        return response()->json(['data' => ['status' => 'reset_code_sent']]);
    }

    public function confirmReset(ResetTransactionPinRequest $request): JsonResponse
    {
        $this->pins->confirmReset(
            $request->user(),
            (string) $request->input('token'),
            (string) $request->input('new_pin'),
        );

        return response()->json(['data' => ['status' => 'pin_reset']]);
    }
}
