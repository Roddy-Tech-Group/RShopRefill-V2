<?php

namespace Tests\Feature\Settings;

use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Moving the account to another inbox is the whole account, so a session
 * alone must not be enough: the customer re-proves the password. Accounts
 * with no password (Google-only) are covered by the old-address notice and
 * the re-verification of the new address instead.
 */
class EmailChangePasswordGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send');
        });
    }

    public function test_changing_the_email_without_the_password_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test']);
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('email', 'new@example.test')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email_change_password']);

        Volt::test('settings.profile')
            ->set('email', 'new@example.test')
            ->set('email_change_password', 'wrong')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email_change_password']);

        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at, 'a refused change must not un-verify the account');
    }

    public function test_the_password_is_not_asked_for_when_the_email_is_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'same@example.test', 'name' => 'Before']);
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('name', 'After')
            ->set('email', 'same@example.test')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('After', $user->fresh()->name);
    }

    public function test_a_passwordless_google_account_can_change_its_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test', 'password' => null, 'google_id' => 'g-1']);
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->set('email', 'new@example.test')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at, 'the new address must prove itself');
    }
}
