<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\GoogleAuthService;
use App\Http\Controllers\Api\Mobile\AppGoogleHandoff;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureReferralCookie;
use App\Models\Referral;
use GuzzleHttp\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * Handles Google OAuth redirect and callback.
 *
 * The actual business logic lives in GoogleAuthService; this controller
 * is a thin HTTP adapter that translates web requests into service calls.
 *
 * The driver() helper (from Roddy's branch) handles local-dev quirks:
 * stateless mode for session mismatches and disabled SSL verification
 * for Windows PHP installs without a CA bundle.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleAuthService $googleAuthService,
        private readonly AppGoogleHandoff $appHandoff,
    ) {}

    /**
     * Redirect the user to Google's OAuth consent screen. When a `?popup=1`
     * query flag is present, stash it in the session so the callback knows to
     * route through the popup-complete page (which closes the popup and
     * notifies the opener) instead of the full dashboard redirect.
     */
    public function redirect(Request $request): RedirectResponse
    {
        session(['google_oauth_popup' => $request->boolean('popup')]);

        // A website sign-in, even in a browser where the app once began one and
        // never came back: this one signs the browser in.
        session()->forget('google_oauth_app');

        return $this->driver()->redirect();
    }

    /**
     * The mobile app's way in (rshoprefill-mobileapp): the same Google sign-in,
     * opened in the phone's browser, ending with the account handed back to
     * the app rather than this browser signed in (AppGoogleHandoff). Open to a
     * browser already signed in to the website, which this sign-in leaves as
     * it was.
     */
    public function app(Request $request): RedirectResponse
    {
        $challenge = (string) $request->query('challenge', '');
        $return = AppGoogleHandoff::returnUrl((string) $request->query('return', ''));

        abort_if($return === null || ! AppGoogleHandoff::isChallenge($challenge), 400);

        session([
            'google_oauth_popup' => false,
            'google_oauth_app' => ['challenge' => $challenge, 'return' => $return],
        ]);

        return $this->driver()->redirect();
    }

    /**
     * Handle the callback from Google after authentication.
     *
     * Delegates to GoogleAuthService for the find-or-create logic,
     * then logs the user in and redirects to dashboard.
     */
    public function callback(): RedirectResponse
    {
        $isPopup = (bool) session()->pull('google_oauth_popup', false);
        $app = session()->pull('google_oauth_app');
        $app = is_array($app) ? $app : null;

        try {
            $googleUser = $this->driver()->user();
        } catch (\Exception $e) {
            Log::warning('Google OAuth callback failed', [
                'error' => $e->getMessage(),
            ]);

            if ($app !== null) {
                return AppGoogleHandoff::back($app['return'], ['error' => __('Google authentication failed. Please try again.')]);
            }

            if ($isPopup) {
                return redirect()->route('auth.popup-complete');
            }

            return redirect()->route('login')
                ->with('status', __('Google authentication failed. Please try again.'));
        }

        try {
            $result = $this->googleAuthService->findOrCreateUser($googleUser);
            $user = $result['user'];

            // Referral attribution for brand-new Google signups: match the
            // 90-day ?ref cookie against a referrer and create the Referral
            // row, mirroring the credentials register flow. Existing users
            // (linking / returning) are never re-attributed.
            if ($result['is_new']) {
                Referral::attribute($user, request()->cookie(CaptureReferralCookie::COOKIE_NAME));
                Cookie::queue(Cookie::forget(CaptureReferralCookie::COOKIE_NAME));
            }

            // Begun in the app: the account goes back to it, to be signed in
            // there on the phone's own terms (AppSignIn), and this browser
            // stays as it was.
            if ($app !== null) {
                return AppGoogleHandoff::back($app['return'], ['code' => $this->appHandoff->issue($user, $app['challenge'])]);
            }

            // A claimed account just had every session revoked, including
            // one this browser may hold, so it always logs in afresh.
            if (! Auth::check() || Auth::id() !== $user->id || $result['claimed']) {
                session()->regenerate();
                // Fires the Login event, so MergeGuestCartOnLogin folds any
                // guest cookie cart into the account here too.
                Auth::login($user, remember: true);
            }

            if ($isPopup) {
                return redirect()->route('auth.popup-complete');
            }

            return redirect()->intended(route('dashboard'));
        } catch (\RuntimeException $e) {
            Log::error('Google OAuth user creation failed', [
                'error' => $e->getMessage(),
            ]);

            if ($app !== null) {
                return AppGoogleHandoff::back($app['return'], ['error' => $e->getMessage()]);
            }

            if ($isPopup) {
                return redirect()->route('auth.popup-complete');
            }

            return redirect()->route('login')
                ->with('status', $e->getMessage());
        }
    }

    /**
     * Build the Google driver.
     *
     * The OAuth `state` round-trip is what stops a login-CSRF / forced
     * account-linking attack, so it stays ON. The callback is a top-level
     * navigation (in the popup window too), which SameSite=Lax cookies
     * accompany, so the session is there to validate it. The usual cause of
     * an InvalidStateException is starting the flow on one host (localhost)
     * and returning to another (127.0.0.1): fix the redirect URI, not the
     * check. GOOGLE_OAUTH_STATELESS=true remains as a local-only escape
     * hatch and is ignored in production.
     *
     * In local dev, we also disable SSL verification on the Guzzle client
     * (Windows PHP installs often lack a CA bundle, causing cURL error 60).
     */
    protected function driver()
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google');

        if (config('services.google.stateless') && ! app()->isProduction()) {
            $driver = $driver->stateless();
        }

        if (app()->environment('local')) {
            $driver = $driver
                ->setHttpClient(new Client(['verify' => false]));
        }

        return $driver;
    }
}
