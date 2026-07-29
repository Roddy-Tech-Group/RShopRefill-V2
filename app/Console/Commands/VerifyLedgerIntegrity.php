<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\LedgerIntegrityService;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Command;

class VerifyLedgerIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ledger:verify {--json : Emit the raw result for dashboards and probes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifies the integrity of the immutable financial double-entry ledger.';

    /**
     * Exits non-zero on any violation. Scheduled nightly and wired to a SEV-1
     * alert: a ledger that disagrees with itself means customer balances cannot
     * be trusted, so this is the one check that justifies waking someone up.
     */
    public function handle(LedgerIntegrityService $integrityService): int
    {
        $result = $integrityService->verifyLedgerIntegrity();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Verifying double-entry ledger integrity...');
        $this->newLine();

        $this->table(
            ['Check', 'Violations'],
            collect($result['checks'])
                ->map(fn (int $count, string $check): array => [
                    str_replace('_', ' ', $check),
                    $count === 0 ? '0' : "<fg=red>{$count}</>",
                ])
                ->values()
                ->all()
        );

        if ($result['status'] === 'passed') {
            $this->info('Ledger integrity verified. No violations found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('CRITICAL: ledger integrity violations detected.');

        foreach ($result['violations'] as $violation) {
            $this->error("  - [{$violation['type']}] {$violation['message']}");
        }

        // The definitive SEV-1. If the ledger disagrees with itself, every wallet
        // balance the platform shows is unproven — and customers are spending
        // against numbers nobody can vouch for.
        app(OperationalAlerter::class)->send(
            severity: AlertSeverity::Sev1,
            key: 'ledger:integrity-violation',
            title: 'Ledger integrity violation',
            message: count($result['violations']).' violation(s) detected in the double-entry ledger. Freeze wallet operations.',
            context: array_filter($result['checks']) + [
                'first_violations' => array_column(array_slice($result['violations'], 0, 5), 'message'),
            ],
            runbook: 'docs/runbooks/ledger-integrity.md',
        );

        $this->newLine();
        $this->line('This is a SEV-1. Freeze wallet operations and investigate before money moves again.');

        return self::FAILURE;
    }
}
