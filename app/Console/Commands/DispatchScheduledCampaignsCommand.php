<?php

namespace App\Console\Commands;

use App\Domain\Notification\Services\CampaignService;
use App\Models\NotificationCampaign;
use Illuminate\Console\Command;

class DispatchScheduledCampaignsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'campaigns:dispatch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Finds and dispatches scheduled push notification campaigns';

    /**
     * Execute the console command.
     */
    public function handle(CampaignService $campaignService)
    {
        $campaigns = NotificationCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($campaigns->isEmpty()) {
            return;
        }

        foreach ($campaigns as $campaign) {
            $this->info("Processing campaign ID: {$campaign->id} - {$campaign->title}");

            // Mark as processing to prevent overlapping cron runs from picking it up
            $campaign->update(['status' => 'processing']);

            try {
                $campaignService->dispatchCampaign($campaign);
                $this->info("Successfully dispatched campaign ID: {$campaign->id}");
            } catch (\Exception $e) {
                $this->error("Failed to dispatch campaign ID: {$campaign->id}. Error: ".$e->getMessage());
                $campaign->update(['status' => 'scheduled']); // Revert so it can be retried
            }
        }
    }
}
