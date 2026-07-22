<?php

namespace App\Console\Commands;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Notifications\GiftCardsUnavailableMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;
use Illuminate\Console\Command;

class BroadcastGiftCardOutage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:broadcast-gift-card-outage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Broadcasts a notification to all users about the temporary gift card outage';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting broadcast...');
        $count = 0;

        User::chunkById(500, function ($users) use (&$count) {
            foreach ($users as $user) {
                // Ensure the user hasn't already received it if the script fails and restarts
                $idempotencyKey = 'giftcard_outage_'.date('Y_m_d').'_user_'.$user->id;

                app(CommunicationManager::class)->send(
                    recipient: $user,
                    category: CommunicationCategory::GiftCardsUnavailable,
                    title: 'Gift Cards Temporarily Unavailable',
                    message: "We're currently performing maintenance on our gift cards service to improve reliability. It will be back as soon as possible.",
                    mailable: new GiftCardsUnavailableMail($user),
                    idempotencyKey: $idempotencyKey
                );
                $count++;
            }
            $this->info("Queued {$count} notifications...");
        });

        $this->info("Done! Dispatched notifications for {$count} users.");
    }
}
