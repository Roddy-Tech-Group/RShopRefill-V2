<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Campaign "Clicked" stat. A push notification click fires from the service
 * worker (no session), so the track endpoint must be public, and a click must
 * roll up onto the campaign's stats_clicked - previously nothing incremented it,
 * so the admin Campaigns list always showed Clicked: 0.
 */
class CampaignClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(int $clicked = 0): NotificationCampaign
    {
        return NotificationCampaign::create([
            'title' => 'Flash Sales',
            'notification_title' => 'Flash Sales',
            'notification_message' => 'Big sale today!',
            'channels' => ['push'],
            'category' => 'marketing',
            'audience_type' => 'all',
            'status' => 'sent',
            'stats_sent' => 117,
            'stats_clicked' => $clicked,
        ]);
    }

    public function test_guest_push_click_is_tracked_and_increments_campaign_clicks(): void
    {
        $campaign = $this->campaign();

        // No auth — a notification click carries no session, so the route is public.
        $this->postJson(route('api.push.track'), [
            'campaign_id' => $campaign->id,
            'notification_id' => null,
            'type' => 'clicked',
            'channel' => 'push',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, $campaign->fresh()->stats_clicked);
        $this->assertDatabaseHas('notification_interactions', [
            'campaign_id' => $campaign->id,
            'interaction_type' => 'clicked',
            'user_id' => null,
        ]);
    }

    public function test_non_click_interactions_do_not_touch_the_click_count(): void
    {
        $campaign = $this->campaign();

        $this->postJson(route('api.push.track'), [
            'campaign_id' => $campaign->id,
            'type' => 'opened',
            'channel' => 'push',
        ])->assertOk();

        $this->assertSame(0, $campaign->fresh()->stats_clicked);
    }
}
