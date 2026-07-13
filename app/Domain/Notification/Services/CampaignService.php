<?php

namespace App\Domain\Notification\Services;

use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CampaignService
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Dispatch a campaign to its intended audience.
     */
    public function dispatchCampaign(NotificationCampaign $campaign): void
    {
        if ($campaign->status !== 'processing' && $campaign->status !== 'active') {
            Log::warning("Campaign {$campaign->id} not in processing/active status (is {$campaign->status}).");

            return;
        }

        // Simplistic audience evaluation (extend as needed)
        $query = User::query();

        if (! empty($campaign->audience_filters['country'])) {
            $query->where('country', $campaign->audience_filters['country']);
        }

        // Active 30 days filter example
        if (! empty($campaign->audience_filters['active_last_30_days'])) {
            $query->where('updated_at', '>=', now()->subDays(30));
        }

        $sentCount = 0;

        $query->chunk(500, function ($users) use ($campaign, &$sentCount) {
            foreach ($users as $user) {
                // Evaluate template variables
                $title = str_replace('{{name}}', $user->name, $campaign->notification_title);
                $body = str_replace('{{name}}', $user->name, $campaign->notification_message);
                $url = $campaign->notification_url;

                $this->dispatcher->dispatch(
                    user: $user,
                    title: $title,
                    message: $body,
                    category: $campaign->category ?? 'marketing',
                    mailable: null,
                    metadata: [
                        'campaign_id' => $campaign->id,
                        'url' => $url,
                    ],
                    idempotencyKey: 'campaign_'.$campaign->id.'_user_'.$user->id
                );

                $sentCount++;
            }
        });

        $campaign->update([
            'status' => 'sent',
            'sent_at' => now(),
            'stats_sent' => $sentCount,
        ]);
    }
}
