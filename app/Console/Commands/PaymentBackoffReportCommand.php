<?php

namespace App\Console\Commands;

use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Models\PaymentBackoffEvent;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads back what the rate limiter has been doing.
 *
 * This is the report that decides whether enforcement should be switched on,
 * and at what thresholds. The question it answers is not "how many customers
 * were held back" but "which kind": a wall of no-shows from guests is the
 * trial-and-error pattern the limiter exists for, whereas declines from
 * customers who have paid before means the thresholds are eating real sales.
 */
class PaymentBackoffReportCommand extends Command
{
    protected $signature = 'payments:backoff-report {--days=7 : How far back to look} {--identities : List the busiest individual identities}';

    protected $description = 'Report what the payment rate limiter has blocked, or would have blocked in shadow mode';

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
            ? '<fg=yellow>ON — customers are being turned away</>'
            : '<fg=green>SHADOW — recording only, nobody is blocked</>'));
        $this->newLine();

        $events = PaymentBackoffEvent::where('created_at', '>=', $since);

        $total = (clone $events)->count();

        if ($total === 0) {
            $this->line('  <fg=gray>No backoff decisions recorded in this window.</>');
            $this->newLine();
            $this->line('  That means either traffic is healthy or the limiter never trips.');
            $this->line('  Leave it in shadow a while longer before drawing conclusions.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line("  <options=bold>{$total}</> decision(s) recorded.");
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

        $distinct = (clone $events)->distinct('identifier')->count('identifier');
        $this->line("  Distinct identities affected: <options=bold>{$distinct}</>");

        if ($distinct > 0) {
            $this->line('  Decisions per identity: <options=bold>'.round($total / $distinct, 1).'</>');
        }
        $this->newLine();

        if ($this->option('identities')) {
            $this->listBusiestIdentities($since);
        }

        $this->interpret($since, $total);

        return self::SUCCESS;
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
            ->select('identifier', 'tier', DB::raw('count(*) as total'), DB::raw('max(no_show_count) as peak_no_shows'), DB::raw('max(decline_count) as peak_declines'))
            ->groupBy('identifier', 'tier')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line('  <options=bold>Busiest identities</>');
        $this->table(
            ['Identity', 'Tier', 'Decisions', 'Peak no-shows', 'Peak declines'],
            $rows->map(fn ($row) => [
                $row->identifier,
                $row->tier,
                $row->total,
                $row->peak_no_shows,
                $row->peak_declines,
            ])->all()
        );
    }

    /**
     * Turn the numbers into the decision they exist to support. Deliberately
     * opinionated: a report nobody can act on is just a table.
     */
    private function interpret(Carbon $since, int $total): void
    {
        $trustedDeclines = PaymentBackoffEvent::where('created_at', '>=', $since)
            ->where('tier', PaymentBackoffManager::TIER_TRUSTED)
            ->where('signal', PaymentBackoffManager::SIGNAL_DECLINE)
            ->count();

        $guestNoShows = PaymentBackoffEvent::where('created_at', '>=', $since)
            ->where('tier', PaymentBackoffManager::TIER_GUEST)
            ->where('signal', PaymentBackoffManager::SIGNAL_NO_SHOW)
            ->count();

        $this->line('  <options=bold>Reading</>');

        if ($guestNoShows / max(1, $total) >= 0.5) {
            $this->line('    <fg=green>Mostly guest no-shows.</> This is the pattern the limiter is for.');
            $this->line('    Enforcement is likely safe to enable at the current thresholds.');
        }

        if ($trustedDeclines > 0) {
            $this->line("    <fg=yellow>{$trustedDeclines} decision(s) hit customers who have paid you before.</>");
            $this->line('    Check these individually before enabling: a returning customer whose');
            $this->line('    card fails twice is a support problem, not an abuse problem.');
        }

        if ($total < 20) {
            $this->line('    <fg=gray>Sample is small. Prefer another week of shadow data over acting now.</>');
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
