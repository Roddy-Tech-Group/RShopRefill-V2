<?php

namespace App\Http\Controllers\Api\V2\Auth;

use App\Domain\Auth\Exceptions\GoogleTokenRejectedException;
use App\Domain\Auth\Exceptions\GoogleUnavailableException;
use App\Domain\Auth\Services\GoogleAuthService;
use App\Domain\Auth\Services\GoogleIdTokenVerifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\GoogleSignInRequest;
use App\Http\Responses\V2\SignInResponder;
use App\Models\Referral;
use App\Support\ApiV2Error;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use PDOException;
use RuntimeException;

/**
 * Google sign-in from the app.
 *
 * The phone does the Google dance natively and sends the resulting ID token
 * here. This verifies it, then hands the result to the SAME GoogleAuthService the
 * website's OAuth callback uses, so account linking, claiming an unverified
 * account, and profile updates all behave identically on both.
 */
class GoogleController extends Controller
{
    public function __construct(
        private GoogleIdTokenVerifier $verifier,
        private GoogleAuthService $googleAuth,
    ) {}

    public function __invoke(GoogleSignInRequest $request, SignInResponder $responder): JsonResponse
    {
        try {
            $googleUser = $this->verifier->verify((string) $request->input('id_token'));
        } catch (GoogleUnavailableException $e) {
            // Nothing is known about the token either way. The app should try again
            // shortly, which is a different screen from "sign in again".
            return ApiV2Error::make('google_unavailable', $e->getMessage(), 503);
        } catch (GoogleTokenRejectedException $e) {
            // The verifier's messages are written for the customer and say which
            // of "expired", "not verified" and "cannot be checked" it was, without
            // saying anything about our configuration.
            return ApiV2Error::make('google_token_rejected', $e->getMessage(), 401);
        }

        try {
            $result = $this->googleAuth->findOrCreateUser($googleUser);
        } catch (PDOException $e) {
            // A database failure is also a RuntimeException, and its message names
            // tables and columns. It is a 500, not a linking refusal.
            throw $e;
        } catch (RuntimeException $e) {
            // Raised when the Google account cannot be attached to an existing
            // one, e.g. the address belongs to a live password account.
            return ApiV2Error::make('google_link_failed', $e->getMessage(), 409);
        }

        $user = $result['user'];

        // Referral attribution for brand-new Google signups only, mirroring the
        // web callback. An existing customer is never re-attributed. The app has
        // no 90-day cookie, so it passes the code itself if it has one.
        if ($result['is_new']) {
            Referral::attribute($user, trim((string) $request->input('referral', '')));
        }

        // Same reason as password login: the Login listeners that are not about
        // devices run for the app too. See EvaluateLoginRiskListener.
        event(new Login('sanctum', $user, false));

        return $responder->respond(
            $user,
            (string) $request->header('X-Installation-ID'),
            $request,
            $result['is_new'] ? 201 : 200,
        );
    }
}
