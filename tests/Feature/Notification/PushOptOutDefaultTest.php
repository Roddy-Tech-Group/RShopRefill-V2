<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Services\NotificationPreferenceService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notifications are opt-out: a brand-new user has push (and the other channels)
 * enabled by default, and turning push off from account settings stops it.
 * (Web push still needs the browser permission grant to actually deliver, but
 * the preference itself defaults on like email/in-app.)
 */
class PushOptOutDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_is_on_by_default_and_can_be_turned_off(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationPreferenceService::class);

        // Default-on: no "enable" needed at the preference level.
        $this->assertTrue($service->isAllowed($user, 'marketing', 'push'));
        $this->assertTrue($service->isAllowed($user, 'order', 'push'));

        // Opt out from account settings.
        $service->updatePreferences($user, ['push_enabled' => false]);
        $this->assertFalse($service->isAllowed($user->fresh(), 'marketing', 'push'));
    }
}
