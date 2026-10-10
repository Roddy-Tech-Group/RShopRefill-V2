<?php

namespace App\Domain\PaymentInsights\Console;

use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Gateway words no classifier rule reads yet, once a day. Each distinct text
 * with how many failures and customers it covered in the last 24 hours, in
 * one message, only when there is something. Rules are then added in a batch
 * and `insights:reclassify` regrades the history. An unclassified message is
 * not a fault to page on; the 10-minute check leaves these alone.
 */
class UnclassifiedGatewayMessagesDigestCommand extends Command
{
    protected $signature = 'insights:unclassified-digest
        {--hours=24 : Look back this many hours}';

    protected $description = 'One daily message listing the gateway failure texts no rule classifies yet';

    public function __construct(private readonly OperationalAlerter $alerter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $groups = PaymentOutcome::query()
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Expired->value, OutcomeType::Cancelled->value])
            ->where('failure_reason_code', 'unrecognised_gateway_message')
            ->where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at')
            ->get()
            ->groupBy(fn (PaymentOutcome $outcome) => ($outcome->provider ?? 'unknown').'|'.$this->text($outcome));

        if ($groups->isEmpty()) {
            $this->info("Nothing unclassified in the last {$hours} hour(s).");

            return self::SUCCESS;
        }

        $lines = $groups
            ->map(fn (Collection $group, string $key) => [
                'provider' => explode('|', $key, 2)[0],
                'text' => explode('|', $key, 2)[1],
                'failures' => $group->count(),
                'customers' => $group->pluck('user_id')->filter()->unique()->count(),
                'methods' => $group->pluck('payment_method')->filter()->unique()->implode(', '),
            ])
            ->sortByDesc('failures')
            ->values();

        $this->table(['Provider', 'Gateway said', 'Failures', 'Customers', 'Methods'], $lines->map(fn (array $l) => [
            $l['provider'], $l['text'], $l['failures'], $l['customers'], $l['methods'],
        ])->all());

        $this->alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'insights:unclassified-digest:'.now()->toDateString(),
            title: 'Gateway messages not yet classified (daily digest)',
            message: sprintf(
                '%d distinct gateway text(s) covering %d failure(s) and %d customer(s) in the last %d hours have no classifier rule yet. Customers were told the payment could not be completed. Add rules for the ones that repeat, then run `php artisan insights:reclassify`.',
                $lines->count(),
                $lines->sum('failures'),
                $groups->flatten(1)->pluck('user_id')->filter()->unique()->count(),
                $hours,
            ),
            context: $lines->take(12)->mapWithKeys(fn (array $l) => [
                Str::limit($l['text'], 90) => sprintf('%d failure(s), %d customer(s), %s via %s', $l['failures'], $l['customers'], $l['provider'], $l['methods'] ?: 'unknown method'),
            ])->all(),
            runbook: 'docs/runbooks/payment-provider-failure.md',
        );

        return self::SUCCESS;
    }

    private function text(PaymentOutcome $outcome): string
    {
        $text = $outcome->metadata['failure_details']['provider_message']
            ?? $outcome->metadata['raw_reason']
            ?? 'no message captured';

        return Str::of((string) $text)->squish()->toString();
    }
}
