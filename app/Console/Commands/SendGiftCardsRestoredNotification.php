<?php

namespace App\Console\Commands;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Mail\Notifications\GiftCardsRestoredMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\User;
use Illuminate\Console\Command;

class SendGiftCardsRestoredNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notify:giftcards-restored {--dry-run : Only print what would be done without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send the gift cards restored notification to all users via Email, Web Push, and Database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting to dispatch notifications for all users...');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE ENABLED. No jobs will actually be dispatched.');
        }

        $count = 0;

        User::chunkById(100, function ($users) use (&$count, $isDryRun) {
            foreach ($users as $user) {
                if (! $isDryRun) {
                    app(CommunicationManager::class)->send(
                        recipient: $user,
                        category: CommunicationCategory::GiftCardsRestored,
                        title: 'Great News! Gift Cards Are Back!',
                        message: 'Our Gift Card service is now fully restored and ready to use. Thank you for your patience and understanding while we worked to get everything back online. We appreciate your continued trust in RShopRefills. Happy shopping!',
                        mailable: new GiftCardsRestoredMail($user),
                        correlationId: 'giftcards_restored_2026_07_'.$user->id,
                        metadata: [
                            'url' => config('app.url').'/dashboard/gift-cards',
                            'campaign_id' => 'giftcards_restored_2026_07',
                        ]
                    );
                }

                $count++;
            }

            $this->info("Processed {$count} users...");
        });

        $this->info("Done! Processed {$count} users total.");

        return Command::SUCCESS;
    }
}
