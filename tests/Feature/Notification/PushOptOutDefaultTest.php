<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\CommunicationChannel;
use App\Domain\Communication\Services\PreferenceManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Communications are opt-out: a brand-new user has push (and the other channels)
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
        $preferences = app(PreferenceManager::class);

        // Default-on: no "enable" needed at the preference level.
        $this->assertTrue($preferences->isAllowed($user, CommunicationCategory::Promotion, CommunicationChannel::Push));
        $this->assertTrue($preferences->isAllowed($user, CommunicationCategory::OrderPlaced, CommunicationChannel::Push));

        // Opt out from account settings.
        $preferences->updatePreferences($user, ['push_enabled' => false]);
        $this->assertFalse($preferences->isAllowed($user->fresh(), CommunicationCategory::Promotion, CommunicationChannel::Push));
    }
}
