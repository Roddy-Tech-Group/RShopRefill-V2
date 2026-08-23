<?php

namespace Tests\Feature\Communication;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Models\CommunicationLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The welcome email waits for a proven address: a fresh registration gets the
 * verification email only, and the welcome follows the Verified event. Accounts
 * created pre-verified (Google) are welcomed immediately. Once per account, ever.
 */
class WelcomeEmailTimingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One send() writes a log row per channel, so count distinct SENDS by
     * looking at the email channel only.
     */
    private function welcomeLogsFor(User $user): int
    {
        return CommunicationLog::query()
            ->where('correlation_id', 'welcome_'.$user->id)
            ->where('channel', 'email')
            ->count();
    }

    public function test_registering_unverified_sends_no_welcome(): void
    {
        $user = User::factory()->unverified()->create();

        event(new Registered($user));

        $this->assertSame(0, $this->welcomeLogsFor($user));
        // The verification email DID go out (Registered -> framework listener).
        $this->assertTrue(
            CommunicationLog::query()
                ->where('user_id', $user->id)
                ->where('category', CommunicationCategory::EmailVerification->value)
                ->exists(),
        );
    }

    public function test_verifying_the_email_sends_the_welcome(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, $this->welcomeLogsFor($user));
    }

    public function test_a_pre_verified_registration_is_welcomed_immediately(): void
    {
        // The Google sign-in shape: created with email_verified_at already set.
        $user = User::factory()->create(['email_verified_at' => now()]);

        event(new Registered($user));

        $this->assertSame(1, $this->welcomeLogsFor($user));
    }

    public function test_the_welcome_is_sent_once_per_account_for_life(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        event(new Registered($user));
        event(new Verified($user));
        event(new Verified($user));

        $this->assertSame(1, $this->welcomeLogsFor($user));
    }

    public function test_guest_shadow_accounts_are_never_welcomed(): void
    {
        $guest = User::factory()->create(['is_guest' => true, 'email_verified_at' => now()]);

        event(new Registered($guest));
        event(new Verified($guest));

        $this->assertSame(0, $this->welcomeLogsFor($guest));
    }
}
