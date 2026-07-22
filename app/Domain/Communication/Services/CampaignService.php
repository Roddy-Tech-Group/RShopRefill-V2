<?php

namespace App\Domain\Communication\Services;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Enums\MailDomain;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CampaignService
{
    public function __construct(private readonly CommunicationManager $communicationManager) {}

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
        $category = CommunicationCategory::tryFrom($campaign->category) ?? CommunicationCategory::Campaign;

        $query->chunk(500, function ($users) use ($campaign, $category, &$sentCount) {
            foreach ($users as $user) {
                // Evaluate template variables
                $title = str_replace('{{name}}', $user->name, $campaign->notification_title);
                $body = str_replace('{{name}}', $user->name, $campaign->notification_message);
                $url = $campaign->notification_url;

                $this->communicationManager->send(
                    recipient: $user,
                    category: $category,
                    title: $title,
                    message: $body,
                    mailable: null, // Note: To send HTML emails, we would need to resolve a generic campaign template here
                    metadata: [
                        'campaign_id' => $campaign->id,
                        'url' => $url,
                        'is_marketing' => $category->mailDomain() === MailDomain::Marketing,
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
