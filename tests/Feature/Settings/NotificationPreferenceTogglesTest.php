<?php

namespace Tests\Feature\Settings;

use App\Models\CommunicationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The profile page's notification toggles must round-trip through the REAL
 * communication_preferences columns. They used to write the retired
 * NotificationPreference key names (marketing_enabled, order_notifications,
 * ...), which mass assignment silently dropped, and read the same nonexistent
 * attributes back as null - so every category toggle reverted to OFF on
 * refresh while only the email/push master switches (real columns) survived.
 */
class NotificationPreferenceTogglesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_user_sees_every_toggle_on(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Volt::test('settings.profile');

        foreach (['notifyEmail', 'notifyPush', 'notifyMarketing', 'notifyOrders', 'notifyWallet', 'notifySecurity'] as $property) {
            $this->assertTrue($component->get($property), "{$property} should default on");
        }
    }

    public function test_category_toggles_persist_and_survive_a_refresh(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->call('toggleNotification', 'marketing')
            ->call('toggleNotification', 'orders');

        // The write lands on the per-category column pairs the dispatch
        // pipeline actually consults...
        $this->assertDatabaseHas('communication_preferences', [
            'user_id' => $user->id,
            'marketing_email' => false,
            'marketing_push' => false,
            'orders_email' => false,
            'orders_push' => false,
            // Untouched groups keep their defaults.
            'wallet_email' => true,
            'wallet_push' => true,
        ]);

        // ...and a fresh mount (the "refresh" that used to reset everything)
        // reads the same state back.
        $reloaded = Volt::test('settings.profile');
        $this->assertFalse($reloaded->get('notifyMarketing'));
        $this->assertFalse($reloaded->get('notifyOrders'));
        $this->assertTrue($reloaded->get('notifyWallet'));

        // Toggling back on restores both channels of the pair.
        $reloaded->call('toggleNotification', 'marketing');
        $this->assertDatabaseHas('communication_preferences', [
            'user_id' => $user->id,
            'marketing_email' => true,
            'marketing_push' => true,
        ]);
    }

    public function test_master_switches_keep_working(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.profile')
            ->call('toggleNotification', 'email')
            ->call('toggleNotification', 'push');

        $this->assertDatabaseHas('communication_preferences', [
            'user_id' => $user->id,
            'email_enabled' => false,
            'push_enabled' => false,
        ]);

        $reloaded = Volt::test('settings.profile');
        $this->assertFalse($reloaded->get('notifyEmail'));
        $this->assertFalse($reloaded->get('notifyPush'));
    }

    /**
     * A toggle reads as ON while EITHER of its channels is still enabled, so a
     * partial state written elsewhere (e.g. the API) cannot render as fully off.
     */
    public function test_partially_enabled_category_reads_as_on(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        CommunicationPreference::create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'push_enabled' => true,
            'wallet_email' => false,
            'wallet_push' => true,
        ]);

        $this->assertTrue(Volt::test('settings.profile')->get('notifyWallet'));
    }

    public function test_security_alerts_cannot_be_toggled_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Volt::test('settings.profile')
            ->call('toggleNotification', 'security');

        // Mandatory category: the call is a no-op and the switch stays on.
        $this->assertTrue($component->get('notifySecurity'));
        $this->assertTrue(Volt::test('settings.profile')->get('notifySecurity'));
    }
}
