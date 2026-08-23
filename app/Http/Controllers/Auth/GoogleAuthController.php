<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Services\GoogleAuthService;
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
 * The actual business logic lives in GoogleAuthService — this controller
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

        try {
            $googleUser = $this->driver()->user();
        } catch (\Exception $e) {
            Log::warning('Google OAuth callback failed', [
                'error' => $e->getMessage(),
            ]);

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

            if (! Auth::check() || Auth::id() !== $user->id) {
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

            if ($isPopup) {
                return redirect()->route('auth.popup-complete');
            }

            return redirect()->route('login')
                ->with('status', $e->getMessage());
        }
    }

    /**
     * Build the Google driver.
     * We run statelessly in all environments to prevent `InvalidStateException`
     * during popup-based auth where session cookies might be dropped by the
     * browser's cross-site cookie restrictions (SameSite).
     *
     * In local dev, we also disable SSL verification on the Guzzle client
     * (Windows PHP installs often lack a CA bundle, causing cURL error 60).
     */
    protected function driver()
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('google')->stateless();

        if (app()->environment('local')) {
            $driver = $driver
                ->setHttpClient(new Client(['verify' => false]));
        }

        return $driver;
    }
}
