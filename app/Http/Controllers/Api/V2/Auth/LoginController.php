<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\LoginRequest;
use App\Http\Responses\V2\SignInResponder;
use App\Models\User;
use App\Support\ApiV2Error;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

/**
 * Password sign-in from the app.
 *
 * Answers with tokens, or with a challenge when this sign-in has to be verified
 * by email. Which of those it is, is LoginRiskEvaluator's decision, the same one
 * the website makes, so a phone cannot get in under rules a browser could not.
 */
class LoginController extends Controller
{
    /**
     * How long every FAILED attempt takes, whatever failed. The website's guard
     * does the same (SessionGuard runs attempts inside a Timebox): without it an
     * unknown address returns after one SELECT and a known one after a bcrypt
     * check, and the difference measures which addresses are registered.
     */
    private const FAILURE_FLOOR_MICROSECONDS = 200_000;

    public function __invoke(LoginRequest $request, SignInResponder $responder): JsonResponse
    {
        $email = Str::lower((string) $request->input('email'));

        /** @var array{0: User|null, 1: bool} $checked */
        $checked = (new Timebox)->call(function (Timebox $timebox) use ($request, $email): array {
            $user = User::where('email', $email)->first();

            // One answer for both "no such address" and "wrong password", so the
            // endpoint cannot be used to find out which of our customers' addresses
            // are registered.
            //
            // A null password means a Google-only account: it must fail here rather
            // than let Hash::check decide, and it fails as ordinary bad credentials
            // rather than announcing how the account signs in.
            $valid = $user !== null
                && $user->password !== null
                && Hash::check((string) $request->input('password'), $user->password);

            if ($valid) {
                // Only failures are padded; a correct sign-in is answered at once.
                $timebox->returnEarly();
            }

            return [$user, $valid];
        }, self::FAILURE_FLOOR_MICROSECONDS);

        [$user, $valid] = $checked;

        if (! $valid) {
            // The same event the website's guard fires, so a failed app sign-in
            // writes the same audit row and trips the same alerting. The password
            // is never part of the payload.
            event(new Failed('sanctum', $user, ['email' => $email]));

            return $this->rejectCredentials();
        }

        if ($refusal = SignInResponder::accountRefusal($user)) {
            return $refusal;
        }

        // Fired so the Login listeners that are NOT about devices run for the app
        // too: cancelling a pending account closure, folding in a guest cart, and
        // whatever is added later. The device and risk half of that listener
        // returns early for the sanctum guard and is done explicitly instead, via
        // the same evaluator. See EvaluateLoginRiskListener.
        event(new Login('sanctum', $user, false));

        return $responder->respond($user, (string) $request->header('X-Installation-ID'), $request);
    }

    private function rejectCredentials(): JsonResponse
    {
        return ApiV2Error::make('invalid_credentials', __('auth.failed'), 401);
    }
}
