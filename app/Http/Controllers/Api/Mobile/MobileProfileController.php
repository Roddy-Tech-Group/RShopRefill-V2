<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Domain\Auth\Services\CustomerRegistration;
use App\Domain\Auth\Services\GoogleSignupCompletion;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's completion step for an account Google created: the website's
 * (livewire/auth/complete-profile) by another road, through the same
 * GoogleSignupCompletion. Until it is done, EnsureAppTokenIsValid holds the
 * token to this, the profile and signing out, as EnsureProfileCompleted holds
 * a browser on the website's step.
 */
class MobileProfileController extends Controller
{
    public function __construct(
        private readonly GoogleSignupCompletion $completion,
        private readonly CustomerRegistration $registration,
    ) {}

    public function complete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // A signup step, not a settings page: a finished account has nothing to
        // complete, and resubmitting would rewrite its phone, country and gender.
        if (! $user->needsProfileCompletion()) {
            return response()->json(['message' => 'Your account is already set up.'], 409);
        }

        $validated = $request->validate($this->completion->rules() + [
            'terms' => ['accepted'],
            // As the app's sign-up asks beside the terms; the Terms of Service
            // already make 18 the minimum age.
            'adult' => ['accepted'],
        ], $this->registration->messages() + [
            'adult.accepted' => 'Please certify that you are 18 years of age or older to continue.',
        ]);

        $this->completion->complete($user, $validated);

        return response()->json(['user' => AppSignIn::profile($user->refresh())]);
    }
}
