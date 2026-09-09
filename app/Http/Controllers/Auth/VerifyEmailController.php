<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Marks an email address verified from the signed link in the welcome email.
 *
 * The link is opened on whatever device the mailbox lives on, and that is
 * often NOT the browser the account was created in (register on the laptop,
 * tap the link on the phone). This route therefore does not require a
 * session: the `signed` middleware proves the link is ours and unexpired,
 * and the hash proves it was minted for this exact email address. That is
 * all a verification needs.
 *
 * Previously the route sat behind `auth`, so the phone was bounced to the
 * login page, became the account's second device, and was greeted with a
 * device OTP and a "new device detected" email before the address could be
 * verified. The very first thing the platform did to a new customer was
 * treat them as a stranger.
 *
 * Deliberately NOT a login: a link proves the mailbox, not the person
 * holding the phone. Signing the visitor in here would turn every
 * verification email into a magic-link login, so an unauthenticated visitor
 * gets a confirmation page and nothing else. The device-trust flow still
 * runs, unchanged, the first time they actually sign in there.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse|Response
    {
        $user = User::find($id);

        abort_if($user === null, 404);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        $alreadyVerified = $user->hasVerifiedEmail();

        if (! $alreadyVerified && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // Same browser the account is signed in on: straight back into the app.
        if ($request->user()?->is($user)) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        // Any other device, or a different account signed in on a shared
        // phone: confirm and stop. Nothing about the current session changes.
        return response()
            ->view('auth.email-verified', [
                'email' => $user->email,
                'alreadyVerified' => $alreadyVerified,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
