<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\WalletLedgerChainVerifier;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Console\Command;

/**
 * Re-proves the wallet ledger's hash chain against the wallets it describes.
 *
 * This is the check that stands behind every balance the platform shows.
 * `ledger:verify` proves the double-entry journals, which nothing in
 * production writes yet, so it passed green on empty tables; this command
 * reads the chain WalletService actually writes. Any violation is a SEV-1:
 * stop wallet operations and investigate before money moves again.
 */
class VerifyLedgerChainCommand extends Command
{
    protected $signature = 'ledger:verify-chain
        {--wallet= : Verify one wallet by id}
        {--json : Emit the raw result for dashboards and probes}';

    protected $description = 'Recompute every wallet ledger hash link and compare each chain with its wallet balance.';

    public function handle(WalletLedgerChainVerifier $verifier, OperationalAlerter $alerter): int
    {
        $walletId = $this->option('wallet') !== null ? (int) $this->option('wallet') : null;

        $result = $verifier->verify($walletId);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf(
            'Verified %d wallet(s), %d ledger event(s), %d transaction(s).',
            $result['wallets'],
            $result['events'],
            $result['transactions'],
        ));

        if ($result['pre_chain_transactions'] > 0) {
            $this->line("{$result['pre_chain_transactions']} transaction(s) predate the chain and have no event by design.");
        }

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
            $this->info('Every link holds and every wallet balance matches its chain.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('CRITICAL: the wallet ledger disagrees with itself.');

        foreach (array_slice($result['violations'], 0, 50) as $violation) {
            $this->error("  - [{$violation['type']}] {$violation['message']}");
        }

        if (count($result['violations']) > 50) {
            $this->error('  ... and '.(count($result['violations']) - 50).' more (use --json for all).');
        }

        // A single wallet check is a hands-on investigation, not the nightly
        // sweep; it reports on the terminal and does not page anyone.
        if ($walletId === null) {
            $alerter->send(
                severity: AlertSeverity::Sev1,
                key: 'ledger:chain-violation',
                title: 'Wallet ledger chain violation',
                message: count($result['violations']).' violation(s) found re-proving the wallet ledger chain. Freeze wallet operations.',
                context: array_filter($result['checks']) + [
                    'wallets_affected' => count(array_unique(array_column($result['violations'], 'wallet_id'))),
                    'first_violations' => array_column(array_slice($result['violations'], 0, 5), 'message'),
                ],
                runbook: 'docs/runbooks/ledger-integrity.md',
            );
        }

        $this->newLine();
        $this->line('This is a SEV-1. Freeze wallet operations and investigate before money moves again.');

        return self::FAILURE;
    }
}
