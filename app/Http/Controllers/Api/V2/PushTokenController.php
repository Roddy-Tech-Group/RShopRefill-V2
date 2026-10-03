<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\RegisterPushTokenRequest;
use App\Models\ExpoPushToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where the app tells us how to reach it.
 *
 * Called on launch and whenever the operating system rotates the token, which it
 * does without warning. So this has to be safe to call constantly: it is an upsert
 * keyed on the device, not an insert, and needs no idempotency key because calling
 * it twice with the same values leaves exactly one row.
 *
 * The one subtlety is that a token belongs to a PHONE, not to an account. Phones get
 * sold, and shared between a customer and their spouse. So registering a token takes
 * it from whoever held it before, or the previous owner's order and wallet
 * notifications would keep arriving on it.
 */
class PushTokenController extends Controller
{
    public function store(RegisterPushTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = (string) $request->input('token');
        $installationId = (string) $request->header('X-Installation-ID');

        $register = function () use ($user, $token, $installationId, $request): void {
            DB::transaction(function () use ($user, $token, $installationId, $request) {
                // Take the token from any other row that holds it, whoever that is.
                // The token is unique in the table, so without this the upsert below
                // would collide, and the customer would silently get no notifications
                // at all. A change of OWNER is logged: it is expected when a phone is
                // sold, and it is also what somebody who learned another customer's
                // token would do to hijack their notifications.
                $holders = ExpoPushToken::where('token', $token)
                    ->where(function ($query) use ($user, $installationId) {
                        $query->where('user_id', '!=', $user->id)
                            ->orWhere('installation_id', '!=', $installationId);
                    })
                    ->get();

                foreach ($holders as $holder) {
                    if ((int) $holder->user_id !== (int) $user->id) {
                        Log::info('api.v2.push_token_changed_owner', [
                            'previous_user_id' => $holder->user_id,
                            'new_user_id' => $user->id,
                            'installation_id' => $installationId,
                        ]);
                    }

                    $holder->delete();
                }

                ExpoPushToken::updateOrCreate(
                    ['user_id' => $user->id, 'installation_id' => $installationId],
                    [
                        'token' => $token,
                        'platform' => $request->input('platform'),
                        'app_version' => $request->input('app_version'),
                        'last_registered_at' => now(),
                    ],
                );
            });
        };

        try {
            $register();
        } catch (UniqueConstraintViolationException) {
            // Two registrations from the same phone racing on launch. The first
            // won the unique index; running once more now finds its row and
            // updates it, which is the outcome the customer wanted anyway.
            $register();
        }

        return response()->json(['data' => ['status' => 'registered']]);
    }

    /**
     * Stop pushing to this device.
     *
     * Called when the customer turns notifications off in the app, and on sign-out:
     * a phone that has been signed out must not keep receiving somebody's wallet
     * activity on its lock screen.
     */
    public function destroy(Request $request): JsonResponse
    {
        ExpoPushToken::where('user_id', $request->user()->id)
            ->where('installation_id', (string) $request->header('X-Installation-ID'))
            ->delete();

        return response()->json(['data' => ['status' => 'unregistered']]);
    }
}
