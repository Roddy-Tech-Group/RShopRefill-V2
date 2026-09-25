<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Models\FinancialLedgerEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-proves the wallet ledger from its raw rows.
 *
 * WalletService writes every credit, debit, lock and unlock as a
 * financial_ledger_events row whose hash covers the event and the hash of
 * the wallet's previous event (FinancialLedgerEvent::generateHash). Nothing
 * ever read that chain back, so an event edited or deleted by hand, a
 * balance changed outside WalletService, or a transaction written without
 * its event all went unnoticed. This verifier walks every wallet's chain and
 * reports each way the rows disagree with each other:
 *
 *   broken_link            an event's hash cannot be reproduced from its
 *                          stored values and the previous event's hash
 *                          (edited, or the event before it was removed)
 *   event_gap              a credit or debit does not start where the
 *                          previous movement ended: the balance was changed
 *                          outside the ledger between the two
 *   balance_drift          wallets.balance differs from the balance the last
 *                          credit/debit event recorded
 *   unchained_transaction  a wallet_transactions row with no ledger event
 *   transaction_gap        a transaction whose balance_before is not the
 *                          previous transaction's balance_after
 *
 * Reproducing a hash needs care: the writer hashed PHP values as it held
 * them, not a canonical form. Credits and debits hashed the float result of
 * `balance_before + amount` (with any floating-point residue), while locks
 * and unlocks hashed the wallet's balance as the decimal string the model
 * returns ("100.0000"). Each stored value is therefore tried in every form
 * the writer could have held it, and the float the writer computed is
 * rebuilt from the previous event's stored balance and this event's amount,
 * which is exactly what the writer read from the database at the time. A
 * link counts as intact when any of those forms reproduces the stored hash.
 * Deliberately independent of WalletService: it reads rows and nothing else.
 */
class WalletLedgerChainVerifier
{
    /**
     * @return array{status: string, wallets: int, events: int, transactions: int, violations: array<int, array<string, mixed>>, checks: array<string, int>}
     */
    public function verify(?int $walletId = null): array
    {
        $violations = [];
        $wallets = 0;
        $events = 0;
        $transactions = 0;
        $preChain = 0;

        // Transactions written before the chain existed have no event and
        // never will; they are counted, not flagged.
        $chainBeganAt = DB::table('financial_ledger_events')->min('created_at');

        $walletRows = DB::table('wallets')
            ->when($walletId !== null, fn ($query) => $query->where('id', $walletId))
            ->orderBy('id')
            ->select('id', 'balance', 'currency')
            ->cursor();

        foreach ($walletRows as $wallet) {
            $wallets++;

            $chain = FinancialLedgerEvent::query()
                ->where('wallet_id', $wallet->id)
                ->orderBy('id')
                ->get();
            $events += $chain->count();

            $walletTransactions = DB::table('wallet_transactions')
                ->where('wallet_id', $wallet->id)
                ->orderBy('id')
                ->select('id', 'type', 'amount', 'balance_before', 'balance_after', 'created_at')
                ->get();
            $transactions += $walletTransactions->count();

            array_push($violations, ...$this->checkChain($chain));
            array_push($violations, ...$this->checkBalance((int) $wallet->id, (string) $wallet->balance, $chain));

            [$found, $skipped] = $this->checkTransactions((int) $wallet->id, $walletTransactions, $chain, $chainBeganAt);
            array_push($violations, ...$found);
            $preChain += $skipped;
        }

        $checks = [];
        foreach ($violations as $violation) {
            $checks[$violation['type']] = ($checks[$violation['type']] ?? 0) + 1;
        }

        return [
            'status' => $violations === [] ? 'passed' : 'failed',
            'wallets' => $wallets,
            'events' => $events,
            'transactions' => $transactions,
            'pre_chain_transactions' => $preChain,
            'violations' => $violations,
            'checks' => $checks + ['broken_link' => 0, 'event_gap' => 0, 'balance_drift' => 0, 'unchained_transaction' => 0, 'transaction_gap' => 0],
        ];
    }

    /**
     * Every event's hash must be reproducible from its stored values and the
     * hash of the event before it.
     *
     * @param  Collection<int, FinancialLedgerEvent>  $chain
     * @return array<int, array<string, mixed>>
     */
    private function checkChain(Collection $chain): array
    {
        $violations = [];
        $previousHash = null;
        // The wallet's balance as the writer read it before this event: the
        // stored balance_after of the last event that moved money. A wallet
        // begins at zero.
        $balanceBefore = 0.0;

        $lastMove = null;

        foreach ($chain as $event) {
            if (! $this->linkHolds($event, $previousHash, $balanceBefore)) {
                $violations[] = [
                    'type' => 'broken_link',
                    'wallet_id' => (int) $event->wallet_id,
                    'event_id' => (int) $event->id,
                    'message' => "Wallet {$event->wallet_id}: event {$event->id} ({$event->event_type} {$event->amount} {$event->currency}) does not reproduce its hash from the stored values and the previous event.",
                ];
            }

            $previousHash = $event->hash;

            if (in_array($event->event_type, ['credit', 'debit'], true)) {
                // The links can all hold and the money still not add up: the
                // writer hashes whatever balance it reads, so a balance edited
                // by hand between two movements leaves a perfect chain with a
                // jump in it. Each movement must start where the last ended.
                $sign = $event->event_type === 'credit' ? 1 : -1;
                $expected = $balanceBefore + $sign * (float) $event->amount;

                if ($lastMove !== null && abs($expected - (float) $event->balance_after) >= 0.00005) {
                    $violations[] = [
                        'type' => 'event_gap',
                        'wallet_id' => (int) $event->wallet_id,
                        'event_id' => (int) $event->id,
                        'message' => sprintf(
                            'Wallet %d: event %d (%s %s) ends at %s, but the movement before it (event %d) ended at %s, so the balance changed by %s outside the ledger.',
                            $event->wallet_id,
                            $event->id,
                            $event->event_type,
                            $event->amount,
                            number_format((float) $event->balance_after, 4, '.', ''),
                            $lastMove->id,
                            number_format($balanceBefore, 4, '.', ''),
                            number_format((float) $event->balance_after - $expected, 4, '.', ''),
                        ),
                    ];
                }

                $balanceBefore = (float) $event->balance_after;
                $lastMove = $event;
            }
        }

        return $violations;
    }

    /**
     * Whether some form of the stored values, chained to the previous hash,
     * reproduces the event's hash.
     */
    private function linkHolds(FinancialLedgerEvent $event, ?string $previousHash, float $balanceBefore): bool
    {
        $amounts = $this->numericForms((string) $event->amount);

        $balances = $this->numericForms((string) $event->balance_after);
        $amountFloat = (float) $event->amount;

        if ($event->event_type === 'credit') {
            $balances[] = $balanceBefore + $amountFloat;
        } elseif ($event->event_type === 'debit') {
            $balances[] = $balanceBefore - $amountFloat;
        }

        $transactionId = $event->wallet_transaction_id !== null ? (int) $event->wallet_transaction_id : null;

        foreach ($amounts as $amount) {
            foreach ($balances as $balance) {
                $hash = FinancialLedgerEvent::generateHash([
                    'wallet_id' => (int) $event->wallet_id,
                    'wallet_transaction_id' => $transactionId,
                    'event_type' => $event->event_type,
                    'amount' => $amount,
                    'balance_after' => $balance,
                    'currency' => $event->currency,
                ], $previousHash);

                if (hash_equals($event->hash, $hash)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The forms a stored decimal could have taken in the writer's hands: the
     * decimal string the model returns, the float a caller passed, and the
     * integer a caller may have passed for a whole amount.
     *
     * @return list<string|float|int>
     */
    private function numericForms(string $stored): array
    {
        $float = (float) $stored;
        $forms = [$stored, $float];

        if (floor($float) === $float && abs($float) < PHP_INT_MAX) {
            $forms[] = (int) $float;
        }

        $trimmed = rtrim(rtrim($stored, '0'), '.');
        if ($trimmed !== $stored && $trimmed !== '') {
            $forms[] = $trimmed;
        }

        return $forms;
    }

    /**
     * The wallet's balance must be what the last money-moving event said it
     * became. Locks and unlocks leave the balance alone, so they are skipped.
     *
     * @param  Collection<int, FinancialLedgerEvent>  $chain
     * @return array<int, array<string, mixed>>
     */
    private function checkBalance(int $walletId, string $balance, Collection $chain): array
    {
        $lastMove = $chain->last(fn (FinancialLedgerEvent $event) => in_array($event->event_type, ['credit', 'debit'], true));

        $expected = $lastMove !== null ? (float) $lastMove->balance_after : 0.0;

        if (abs((float) $balance - $expected) < 0.00005) {
            return [];
        }

        return [[
            'type' => 'balance_drift',
            'wallet_id' => $walletId,
            'message' => sprintf(
                'Wallet %d: balance reads %s but the ledger chain ends at %s%s.',
                $walletId,
                number_format((float) $balance, 4, '.', ''),
                number_format($expected, 4, '.', ''),
                $lastMove !== null ? " (event {$lastMove->id})" : ' (no events)',
            ),
        ]];
    }

    /**
     * Every transaction must have its ledger event, and the transactions must
     * run on from one another without a gap.
     *
     * @param  Collection<int, object>  $walletTransactions
     * @param  Collection<int, FinancialLedgerEvent>  $chain
     * @return array{0: array<int, array<string, mixed>>, 1: int} violations, and how many transactions predate the chain
     */
    private function checkTransactions(int $walletId, Collection $walletTransactions, Collection $chain, ?string $chainBeganAt): array
    {
        $violations = [];
        $preChain = 0;
        $chained = $chain->pluck('wallet_transaction_id')->filter()->map(fn ($id) => (int) $id)->flip();
        $previousAfter = null;

        foreach ($walletTransactions as $transaction) {
            $beforeChain = $chainBeganAt === null || (string) $transaction->created_at < $chainBeganAt;

            if ($beforeChain && ! $chained->has((int) $transaction->id)) {
                $preChain++;
            } elseif (! $chained->has((int) $transaction->id)) {
                $violations[] = [
                    'type' => 'unchained_transaction',
                    'wallet_id' => $walletId,
                    'transaction_id' => (int) $transaction->id,
                    'message' => "Wallet {$walletId}: transaction {$transaction->id} ({$transaction->type} {$transaction->amount}) has no ledger event.",
                ];
            }

            if ($previousAfter !== null && abs((float) $transaction->balance_before - $previousAfter) >= 0.00005) {
                $violations[] = [
                    'type' => 'transaction_gap',
                    'wallet_id' => $walletId,
                    'transaction_id' => (int) $transaction->id,
                    'message' => sprintf(
                        'Wallet %d: transaction %d starts at %s but the one before it ended at %s.',
                        $walletId,
                        $transaction->id,
                        number_format((float) $transaction->balance_before, 4, '.', ''),
                        number_format($previousAfter, 4, '.', ''),
                    ),
                ];
            }

            $previousAfter = (float) $transaction->balance_after;
        }

        return [$violations, $preChain];
    }
}
