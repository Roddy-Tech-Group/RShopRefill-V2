<?php

namespace App\Console\Commands;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentDecline;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads back what the rate limiter has been doing, and what it has been fed.
 *
 * It answers two questions: which cards did the gateway refuse and on whose
 * account, and who was paused or locked because of it. Pauses come from failed
 * attempts on any rail; locks come only from five different cards refused as
 * fraud, whoever the customer is. Every lock deserves a look against the
 * customer page: a stranger rotating stolen cards stays locked, and a real
 * customer who contacts support is lifted.
 */
class PaymentBackoffReportCommand extends Command
{
    protected $signature = 'payments:backoff-report {--days=7 : How far back to look} {--identities : List the busiest individual identities}';

    protected $description = 'Report the refused cards the gateway reported and what the payment rate limiter did, or would have done, about them';

    public function handle(PaymentBackoffManager $manager): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $enforcing = $manager->enforcementEnabled();

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Payment Backoff Report</>');
        $this->line("  Last {$days} day(s), since {$since->toDayDateTimeString()}");
        $this->line('  Master switch: '.$this->badge(FeatureFlag::on('progressive_backoff')));
        $this->line('  Enforcement:   '.($enforcing
            ? '<fg=yellow>ON — customers are being paused or locked</>'
            : '<fg=green>SHADOW — recording only, nobody is blocked</>'));
        $this->newLine();

        $this->refusedCards($since);

        $events = PaymentBackoffEvent::where('created_at', '>=', $since);
        $total = (clone $events)->count();

        if ($total === 0) {
            $this->line('  <fg=gray>No pauses or locks recorded in this window.</>');
            $this->line('  Nobody reached three failed attempts in a row, and nobody ran five fraud-refused cards.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line("  <options=bold>{$total}</> ladder decision(s) recorded.");
        $this->newLine();

        $this->breakdown('By signal', (clone $events)
            ->select('signal', DB::raw('count(*) as total'))
            ->groupBy('signal')->pluck('total', 'signal')->all(), $total);

        $this->breakdown('By trust tier', (clone $events)
            ->select('tier', DB::raw('count(*) as total'))
            ->groupBy('tier')->pluck('total', 'tier')->all(), $total);

        $this->breakdown('By product line', (clone $events)
            ->select('product_line', DB::raw('count(*) as total'))
            ->groupBy('product_line')->pluck('total', 'product_line')->all(), $total);

        // Which rung each decision landed on, read from the wait it imposed.
        // The ladder is 60 seconds, then five minutes, then six hours, so the
        // wait is the rung and no extra column has to be stored for it.
        $this->breakdown('By rung', [
            '60 second pause' => (clone $events)->where('wait_seconds', '<=', PaymentBackoffManager::PAUSE_SECONDS)->count(),
            '5 minute pause' => (clone $events)
                ->where('wait_seconds', '>', PaymentBackoffManager::PAUSE_SECONDS)
                ->where('wait_seconds', '<=', PaymentBackoffManager::HOLD_SECONDS)->count(),
            '6 hour device lock' => (clone $events)->where('wait_seconds', '>', PaymentBackoffManager::HOLD_SECONDS)->count(),
        ], $total);

        $distinct = (clone $events)->distinct('identifier')->count('identifier');
        $this->line("  Distinct identities affected: <options=bold>{$distinct}</>");

        $locks = (clone $events)->where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING);
        $lockCount = (clone $locks)->count();
        if ($lockCount > 0) {
            $lifted = (clone $locks)->whereNotNull('lifted_at')->count();
            $this->line("  Locks: <options=bold>{$lockCount}</>, lifted by an admin: <options=bold>{$lifted}</>");
        }
        $this->newLine();

        if ($this->option('identities')) {
            $this->listBusiestIdentities($since);
        }

        $this->interpret($since, $total);

        return self::SUCCESS;
    }

    /**
     * What the gateway told us it refused. This section fills in whether or
     * not anyone crossed a threshold, so an empty ladder can be told apart
     * from an empty feed.
     */
    private function refusedCards(Carbon $since): void
    {
        $declines = PaymentDecline::where('declined_at', '>=', $since);
        $charges = (clone $declines)->count();

        $this->line('  <options=bold>Refused cards (from the gateway)</>');

        if ($charges === 0) {
            $this->line('    <fg=gray>No refused charges recorded. Either nothing was declined, or no popup session ended in this window.</>');
            $this->newLine();

            return;
        }

        $cards = (clone $declines)->distinct('card_fingerprint')->count('card_fingerprint');
        $identities = (clone $declines)->whereNotNull('user_id')->distinct('user_id')->count('user_id');
        $devices = (clone $declines)->whereNotNull('device_uuid')->distinct('device_uuid')->count('device_uuid');

        $this->line(sprintf('    %d refused charge(s) on %d distinct card(s), %d account(s), %d device(s)', $charges, $cards, $identities, $devices));

        $byClass = (clone $declines)
            ->select('verdict_class', DB::raw('count(*) as total'))
            ->groupBy('verdict_class')->pluck('total', 'verdict_class')->all();

        foreach ([DeclineVerdict::CLASS_FRAUD => 'fraud-class', DeclineVerdict::CLASS_SOFT => 'soft', DeclineVerdict::CLASS_IGNORED => 'ignored (ours or unsupported)'] as $class => $label) {
            $count = (int) ($byClass[$class] ?? 0);
            $share = $charges > 0 ? round($count / $charges * 100) : 0;
            $this->line(sprintf('    %-30s %5d  %3d%%', $label, $count, $share));
        }

        // Which methods are failing. A bank transfer or mobile money rail
        // failing in bulk is far more likely to be a broken rail of ours than a
        // crowd of customers misbehaving, and it shows up here first.
        $byMethod = (clone $declines)
            ->select('payment_method', DB::raw('count(*) as total'))
            ->groupBy('payment_method')->orderByDesc('total')->limit(6)->pluck('total', 'payment_method');

        if ($byMethod->isNotEmpty()) {
            $this->line('    By method: '.$byMethod->map(fn ($count, $method) => ($method ?: 'unknown')." x{$count}")->implode(', '));
        }

        $topVerdicts = (clone $declines)
            ->select('verdict', DB::raw('count(*) as total'))
            ->groupBy('verdict')->orderByDesc('total')->limit(5)->pluck('total', 'verdict');

        if ($topVerdicts->isNotEmpty()) {
            $this->line('    Most common verdicts: '.$topVerdicts->map(fn ($count, $verdict) => "\"{$verdict}\" x{$count}")->implode(', '));
        }

        $this->newLine();
    }

    /**
     * @param  array<string, int>  $rows
     */
    private function breakdown(string $title, array $rows, int $total): void
    {
        if ($rows === []) {
            return;
        }

        $this->line("  <options=bold>{$title}</>");

        arsort($rows);

        foreach ($rows as $label => $count) {
            $share = $total > 0 ? round($count / $total * 100) : 0;
            $bar = str_repeat('█', max(1, (int) round($share / 5)));
            $this->line(sprintf('    %-14s %5d  %3d%%  <fg=blue>%s</>', $label, $count, $share, $bar));
        }

        $this->newLine();
    }

    private function listBusiestIdentities(Carbon $since): void
    {
        $rows = PaymentBackoffEvent::where('created_at', '>=', $since)
            ->select('identifier', 'tier', DB::raw('count(*) as total'), DB::raw('max(attempt_count) as peak_attempts'), DB::raw('max(decline_count) as peak_cards'), DB::raw("sum(case when signal = 'card_testing' then 1 else 0 end) as locks"))
            ->groupBy('identifier', 'tier')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line('  <options=bold>Busiest identities</>');
        $this->table(
            ['Identity', 'Tier', 'Decisions', 'Peak failed attempts', 'Peak refused cards', 'Locks'],
            $rows->map(fn ($row) => [
                $row->identifier,
                $row->tier,
                $row->total,
                $row->peak_attempts,
                $row->peak_cards,
                $row->locks,
            ])->all()
        );
    }

    /**
     * Turn the numbers into the decision they exist to support. Deliberately
     * opinionated: a report nobody can act on is just a table.
     */
    private function interpret(Carbon $since, int $total): void
    {
        $trustedPauses = PaymentBackoffEvent::where('created_at', '>=', $since)
            ->where('tier', PaymentBackoffManager::TIER_TRUSTED)
            ->where('signal', PaymentBackoffManager::SIGNAL_FAILED_ATTEMPTS)
            ->count();

        $locks = PaymentBackoffEvent::where('created_at', '>=', $since)
            ->where('signal', PaymentBackoffManager::SIGNAL_CARD_TESTING)
            ->count();

        $this->line('  <options=bold>Reading</>');

        if ($locks > 0) {
            $this->line("    <fg=yellow>{$locks} lock(s) reached.</> Open each one against the customer page:");
            $this->line('    each one is five different cards refused as fraud. Locks on customers who have paid before deserve the closest look.');
        }

        if ($trustedPauses > 0) {
            $this->line("    <fg=yellow>{$trustedPauses} pause(s) hit customers who have paid you before.</>");
            $this->line('    Check these individually: a returning customer failing repeatedly is usually a rail problem worth fixing.');
        }

        if ($total < 20) {
            $this->line('    <fg=gray>Sample is small. Read it again in a few days before drawing conclusions.</>');
        }

        $this->newLine();
        $this->line('  Enable enforcement with:  <fg=cyan>features.payment_backoff_enforce = on</>');
        $this->newLine();
    }

    private function badge(bool $on): string
    {
        return $on ? '<fg=green>on</>' : '<fg=red>off</>';
    }
}
