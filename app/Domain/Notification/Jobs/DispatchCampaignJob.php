<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\Services\CampaignService;
use App\Models\NotificationCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fans a campaign out to its audience off the request cycle.
 *
 * Used by the "Send Immediately" action so an admin gets near-instant dispatch
 * (queue latency only) instead of waiting for the per-minute campaigns:dispatch
 * cron. The heavy per-user enqueue loop runs here, on the queue, rather than
 * blocking the web request or the scheduler process.
 */
class DispatchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A large audience fan-out can take a while; give it room.
     */
    public int $timeout = 1800;

    public function __construct(private readonly NotificationCampaign $campaign) {}

    public function handle(CampaignService $campaignService): void
    {
        $campaign = $this->campaign->fresh();

        // Already sent or cancelled between enqueue and run - nothing to do.
        if ($campaign === null || ! in_array($campaign->status, ['scheduled', 'processing', 'active'], true)) {
            Log::info('DispatchCampaignJob skipped; campaign is not dispatchable.', [
                'campaign_id' => $this->campaign->id,
                'status' => $campaign?->status,
            ]);

            return;
        }

        // Claim it so an overlapping cron tick can't dispatch the same campaign twice.
        $campaign->update(['status' => 'processing']);

        try {
            $campaignService->dispatchCampaign($campaign);
        } catch (\Throwable $e) {
            // Revert so the cron (or a retry) can pick it up again.
            $campaign->update(['status' => 'scheduled']);

            throw $e;
        }
    }
}
