<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Jobs\DispatchCampaignJob;
use App\Domain\Notification\Jobs\SendAsynchronousNotificationJob;
use App\Domain\Notification\Services\CampaignService;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * "Send Immediately" hands a campaign straight to DispatchCampaignJob so it goes
 * out on the queue within seconds, instead of waiting for the per-minute
 * campaigns:dispatch cron. The job fans the campaign out to its audience (one
 * queued notification per user) and finalises the campaign as 'sent'.
 */
class SendCampaignNowTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(string $status = 'processing'): NotificationCampaign
    {
        return NotificationCampaign::create([
            'title' => 'Flash sale',
            'notification_title' => 'Flash sale',
            'notification_message' => 'Up to 50% off today',
            'notification_url' => '/dashboard',
            'channels' => ['push'],
            'category' => 'marketing',
            'audience_type' => 'all',
            'audience_filters' => [],
            'status' => $status,
            'scheduled_at' => now(),
        ]);
    }

    public function test_job_enqueues_one_notification_per_user_and_marks_campaign_sent(): void
    {
        Queue::fake();
        User::factory()->count(3)->create();
        $campaign = $this->campaign();

        (new DispatchCampaignJob($campaign))->handle(app(CampaignService::class));

        Queue::assertPushed(SendAsynchronousNotificationJob::class, 3);
        $this->assertSame('sent', $campaign->fresh()->status);
    }

    public function test_job_skips_a_campaign_that_was_already_sent(): void
    {
        Queue::fake();
        User::factory()->create();
        $campaign = $this->campaign('sent');

        (new DispatchCampaignJob($campaign))->handle(app(CampaignService::class));

        Queue::assertNotPushed(SendAsynchronousNotificationJob::class);
        $this->assertSame('sent', $campaign->fresh()->status);
    }

    public function test_editor_send_now_claims_processing_and_queues_the_dispatch_job(): void
    {
        Queue::fake();
        $campaign = $this->campaign('draft');

        Volt::test('admin.campaign-editor', ['id' => $campaign->id])
            ->set('scheduleType', 'now')
            ->set('pushTitle', 'Flash sale')
            ->set('pushBody', 'Up to 50% off today')
            ->call('save');

        $this->assertSame('processing', $campaign->fresh()->status);
        Queue::assertPushed(DispatchCampaignJob::class);
    }
}
