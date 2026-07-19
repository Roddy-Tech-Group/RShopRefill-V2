<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\LedgerIntegrityService;
use Illuminate\Console\Command;

class VerifyLedgerIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ledger:verify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifies the integrity of the immutable financial double-entry ledger.';

    /**
     * Execute the console command.
     */
    public function handle(LedgerIntegrityService $integrityService)
    {
        $this->info('Starting Ledger Integrity Verification...');

        $result = $integrityService->verifyLedgerIntegrity();

        if ($result['status'] === 'passed') {
            $this->info('Ledger integrity verified successfully. No violations found.');
            return Command::SUCCESS;
        }

        $this->error('CRITICAL: Ledger integrity violations detected!');
        
        foreach ($result['violations'] as $violation) {
            $this->error("- [{$violation['type']}] {$violation['message']}");
        }

        // Note: In production, this would trigger PagerDuty or Slack alerts.

        return Command::FAILURE;
    }
}
