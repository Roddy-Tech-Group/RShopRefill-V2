<?php

namespace App\Console\Commands;

use App\Models\LedgerJournal;
use App\Models\KycSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PurgeComplianceData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'compliance:purge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge or anonymize data beyond the legal retention period (e.g., GDPR, NDPR).';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting compliance data purge process...');

        // 1. Purge failed KYC data older than 1 year (NDPR standard)
        $failedKycThreshold = Carbon::now()->subYear();
        $deletedKyc = KycSubmission::whereIn('status', ['rejected', 'expired'])
            ->where('created_at', '<', $failedKycThreshold)
            ->delete();
        
        if ($deletedKyc > 0) {
            Log::info("Compliance: Purged {$deletedKyc} old rejected/expired KYC records.");
            $this->info("Purged {$deletedKyc} old KYC records.");
        }

        // 2. Anonymize user logs older than 7 years (Financial data retention)
        // This is a placeholder since the ledger should remain immutable, but PII might need scrubbing.
        // For example, if we log IP addresses or UserAgents, we purge them after 7 years.
        
        // 3. Purge orphaned FX Quotes
        $deletedQuotes = \App\Models\FXQuote::where('expires_at', '<', Carbon::now()->subDays(30))
            ->where('status', \App\Models\FXQuote::STATUS_EXPIRED)
            ->delete();
            
        if ($deletedQuotes > 0) {
             Log::info("Compliance: Purged {$deletedQuotes} expired FX Quotes.");
             $this->info("Purged {$deletedQuotes} expired FX Quotes.");
        }

        $this->info('Compliance purge complete.');
        return Command::SUCCESS;
    }
}
