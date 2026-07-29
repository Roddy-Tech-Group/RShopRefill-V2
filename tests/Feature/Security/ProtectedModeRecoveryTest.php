<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Protected Mode recovery lifts a fraud restriction and unlocks the transaction
 * PIN, so it is the single most valuable thing on the account for an attacker
 * who already has a session (stolen cookie, shared device, or the account owner
 * trying to shake off a restriction fraud-ops applied).
 *
 * Livewire exposes every PUBLIC method as a client-callable endpoint, so the
 * final step must not be public: $wire.completeRecovery() from the browser
 * console would otherwise clear restricted_at without the password, the emailed
 * OTP, or the transaction PIN. Public properties are client-writable for the
 * same reason, so the step machine and the verification flag are #[Locked].
 */
class ProtectedModeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function restrictedUser(): User
    {
        return User::factory()->create([
            'restricted_at' => now(),
            'pin_status' => 'locked',
        ]);
    }

    /** Did the given client action get rejected? Never swallows PHPUnit's own failures. */
    private function wasRejected(callable $clientAction): bool
    {
        try {
            $clientAction();

            return false;
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function test_the_final_step_is_not_callable_from_the_client(): void
    {
        $user = $this->restrictedUser();
        $this->actingAs($user);

        // Livewire refuses to route to a non-public method. Which exception it
        // raises is a framework detail; that it refuses is the contract.
        $rejected = $this->wasRejected(
            fn () => Volt::test('security.recover')->call('completeRecovery')
        );

        $this->assertTrue($rejected, 'completeRecovery must not be reachable from the client.');

        $user->refresh();
        $this->assertNotNull($user->restricted_at, 'the restriction must survive the attempt');
        $this->assertSame('locked', $user->pin_status, 'the PIN must stay locked');
    }

    public function test_the_step_machine_cannot_be_driven_from_the_client(): void
    {
        $this->actingAs($this->restrictedUser());

        // #[Locked] makes Livewire reject a client-side write outright, so a
        // caller cannot skip the password screen by jumping to the PIN step.
        $this->assertTrue(
            $this->wasRejected(fn () => Volt::test('security.recover')->set('step', 'pin')),
            'step must be locked against client writes.'
        );
    }

    public function test_the_verification_flag_cannot_be_forged_by_the_client(): void
    {
        $this->actingAs($this->restrictedUser());

        $this->assertTrue(
            $this->wasRejected(fn () => Volt::test('security.recover')->set('identityVerified', true)),
            'identityVerified must be locked against client writes.'
        );
    }

    public function test_an_unrestricted_user_is_sent_away_from_the_recovery_screen(): void
    {
        $this->actingAs(User::factory()->create(['restricted_at' => null]));

        $this->get(route('security.recover'))->assertRedirect(route('dashboard'));
    }
}
