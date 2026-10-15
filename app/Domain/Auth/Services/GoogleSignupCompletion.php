<?php

namespace App\Domain\Auth\Services;

use App\Domain\Communication\Listeners\System\WelcomeListener;
use App\Models\NewsletterSubscriber;
use App\Models\Referral;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Finishing an account Google created. Google hands over a name, an email and a
 * picture, so the country, phone and gender the register wizard asks for are
 * asked for once, here, by the wizard's own rules (CustomerRegistration), and an
 * account finished this way is indistinguishable from one registered by
 * password. For the website's completion step (livewire/auth/complete-profile)
 * and the mobile app's (Api\Mobile\MobileProfileController) alike.
 *
 * What stays with each caller is its surface: the website's form field names,
 * newsletter tick and referral cookie; the app's token.
 */
final class GoogleSignupCompletion
{
    public function __construct(
        private readonly CustomerRegistration $registration,
    ) {}

    /**
     * Country, phone and gender as the wizard checks them, and an invite code.
     * Each caller adds its own terms field.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->registration->rulesForStep(2) + [
            'referral' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Stores the missing details and lifts the hold (EnsureProfileCompleted on
     * the website, EnsureAppTokenIsValid in the app).
     *
     * @param  array<string, mixed>  $validated  What rules() passed, the terms accepted beside it
     */
    public function complete(User $user, array $validated, bool $newsletter = false): void
    {
        // Canonicalise to E.164 (+2348031234567) before the uniqueness check:
        // a national-number comparison could not see that 0803... and +234803...
        // are the same line. One verified line, one account.
        $phoneE164 = (new PhoneNumber((string) $validated['phone'], [(string) $validated['country']]))->formatE164();

        if (User::where('phone', $phoneE164)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'This phone number is already registered to another account.',
            ]);
        }

        $user->forceFill([
            'country' => $validated['country'],
            'phone' => $phoneE164,
            'gender' => $validated['gender'],
            // Set only on a submission that passed the terms, so this timestamp
            // doubles as the record of when they accepted.
            'profile_completed_at' => now(),
        ])->save();

        // Newsletter opt-in feeds the same list the admin Newsletter page
        // broadcasts to. firstOrCreate keeps it idempotent, and an address that
        // had previously unsubscribed is re-activated because this tick is an
        // explicit, current choice.
        if ($newsletter && FeatureFlag::on('newsletter_signup')) {
            $subscriber = NewsletterSubscriber::firstOrCreate(
                ['email' => $user->email],
                ['status' => 'active', 'subscribed_at' => now(), 'source' => 'google_signup'],
            );

            if ($subscriber->status !== 'active') {
                $subscriber->update([
                    'status' => 'active',
                    'subscribed_at' => now(),
                    'unsubscribed_at' => null,
                ]);
            }
        }

        // Referral attribution. The Google callback already ran any invite
        // cookie against this account, and Referral::attribute is idempotent
        // per referred user, so a code given here can only fill a gap; it can
        // never overwrite an attribution that already stuck.
        Referral::attribute($user, (string) ($validated['referral'] ?? ''));

        // The welcome email waited for this moment. WelcomeListener suppressed
        // it at the Google callback because the account was still owing this
        // step, so an abandoned signup is never welcomed; now that the hold is
        // lifted the account is real and the greeting knows their name. The
        // listener's correlation id keeps it once-per-account for life, so
        // calling it here can never produce a second welcome. A mail failure
        // must not cost the customer their finished registration, which is
        // already saved above.
        try {
            app(WelcomeListener::class)->handle(new Registered($user));
        } catch (\Throwable $e) {
            Log::warning('Welcome email failed after signup completion', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
