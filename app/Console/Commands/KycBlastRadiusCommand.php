<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Services\KycTierService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\PaymentInsights\Services\PaymentBackoffManager;
use App\Domain\Shared\Enums\FundingStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletFunding;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Who hits the identity-verification wall the day kyc_enforce is switched on.
 *
 * The cumulative dial is a ratchet: a customer whose money in over the window
 * already sits at or above it needs verification on their very next order or
 * funding, however small. This counts those customers before the flip, so the
 * number is known and not discovered from support tickets. Run it before every
 * change to the dials as well.
 */
class KycBlastRadiusCommand extends Command
{
    protected $signature = 'kyc:blast-radius {--top=10 : How many of the closest unverified customers to list}';

    protected $description = 'Count the customers who would be asked to verify their identity on their next money action';

    public function handle(KycTierService $tiers): int
    {
        $cumulative = $tiers->cumulativeThresholdUsd();
        $windowDays = $tiers->cumulativeWindowDays();
        $since = now()->subDays($windowDays);

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>KYC Blast Radius</>');
        $this->line(sprintf('  Single transaction: <options=bold>$%s</>   Cumulative: <options=bold>$%s</> over %d days (since %s)',
            number_format($tiers->singleThresholdUsd(), 2), number_format($cumulative, 2), $windowDays, $since->toDateString()));
        $this->line('  Verification switch: '.$this->badge(FeatureFlag::on('features.kyc_verification_enabled', false)));
        $this->line('  Enforcement:         '.($tiers->enforcementEnabled()
            ? '<fg=yellow>ON — step-ups are being demanded</>'
            : '<fg=green>SHADOW — recording only, nobody is asked</>'));
        $this->newLine();

        if ($cumulative <= 0) {
            $this->line('  <fg=gray>The cumulative dial is unset (0), so nobody is gated on volume.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        // Only customers who brought money in during the window can have a
        // money-in total at all; everyone else is 0 and needs no query.
        $userIds = WalletFunding::query()
            ->where('status', FundingStatus::Completed)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('user_id')
            ->merge(Order::query()
                ->where('payment_status', PaymentStatus::Paid)
                ->whereNotIn('payment_method', PaymentBackoffManager::BALANCE_PROVIDERS)
                ->where('created_at', '>=', $since)
                ->distinct()
                ->pluck('user_id'))
            ->filter()
            ->unique()
            ->values();

        $rows = User::query()
            ->whereIn('id', $userIds)
            ->where('is_guest', false)
            ->get(['id', 'kyc_status'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'verified' => $user->isKycVerified(),
                'money_in' => $tiers->moneyInUsd($user, $since),
            ]);

        if ($rows->isEmpty()) {
            $this->line('  <fg=gray>No customer brought money in during the window.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $unverified = $rows->where('verified', false);
        $overLine = $unverified->where('money_in', '>=', $cumulative);

        $this->line(sprintf('  <options=bold>%d</> customer(s) brought money in; <options=bold>%d</> of them are not identity-verified.',
            $rows->count(), $unverified->count()));
        $this->line(sprintf('  <fg=%s;options=bold>%d</> unverified customer(s) are already at or over $%s: they are asked to verify on their next order or funding.',
            $overLine->isEmpty() ? 'green' : 'yellow', $overLine->count(), number_format($cumulative, 2)));
        $this->newLine();

        $this->bands($unverified, $cumulative);
        $this->closest($unverified, $cumulative, max(1, (int) $this->option('top')));

        return self::SUCCESS;
    }

    /**
     * Where the unverified customers sit against the dial, in quarters.
     *
     * @param  Collection<int, array{id: int, verified: bool, money_in: float}>  $unverified
     */
    private function bands(Collection $unverified, float $cumulative): void
    {
        $this->line('  <options=bold>Unverified customers by share of the cumulative dial</>');

        $bands = [
            'under 25%' => fn (float $v) => $v < $cumulative * 0.25,
            '25% to 50%' => fn (float $v) => $v >= $cumulative * 0.25 && $v < $cumulative * 0.5,
            '50% to 75%' => fn (float $v) => $v >= $cumulative * 0.5 && $v < $cumulative * 0.75,
            '75% to 100%' => fn (float $v) => $v >= $cumulative * 0.75 && $v < $cumulative,
            'at or over' => fn (float $v) => $v >= $cumulative,
        ];

        foreach ($bands as $label => $inBand) {
            $count = $unverified->filter(fn (array $row) => $inBand($row['money_in']))->count();
            $this->line(sprintf('    %-12s %d', $label, $count));
        }

        $this->newLine();
    }

    /**
     * The unverified customers nearest to (or past) the line, ids only.
     *
     * @param  Collection<int, array{id: int, verified: bool, money_in: float}>  $unverified
     */
    private function closest(Collection $unverified, float $cumulative, int $top): void
    {
        $nearest = $unverified->sortByDesc('money_in')->take($top)->values();

        if ($nearest->isEmpty()) {
            return;
        }

        $this->line("  <options=bold>Closest {$nearest->count()} unverified customer(s)</>");
        $this->table(
            ['User', 'Money in (USD)', 'Share of dial', 'Next action'],
            $nearest->map(fn (array $row) => [
                $row['id'],
                number_format($row['money_in'], 2),
                sprintf('%d%%', (int) floor($row['money_in'] / $cumulative * 100)),
                $row['money_in'] >= $cumulative ? 'verify' : sprintf('$%s more', number_format($cumulative - $row['money_in'], 2)),
            ])->all(),
        );
        $this->newLine();
    }

    private function badge(bool $on): string
    {
        return $on ? '<fg=green>ON</>' : '<fg=gray>OFF</>';
    }
}
