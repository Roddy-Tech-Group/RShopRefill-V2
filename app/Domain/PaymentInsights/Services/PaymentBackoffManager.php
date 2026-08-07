<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Enums\FailureStage;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentOutcome;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Decides whether a customer should be held back before starting another
 * payment.
 *
 * The original implementation counted every unsuccessful outcome together:
 * declines, cancellations and expiries alike. On real traffic the overwhelming
 * majority of those are abandonment, so the limiter spent its time throttling
 * customers who opened checkout and wandered off, while a card tester pacing
 * themselves barely noticed it. It measured shopping behaviour and called it
 * abuse.
 *
 * Two distinct counters replace it:
 *
 *   declines  A payment was actually submitted and refused. This is the
 *             card-testing signature and the one worth reacting to sharply.
 *
 *   no_shows  Pay was tapped but no payment method was ever engaged, so the
 *             checkout produced records and nothing else. This is the
 *             trial-and-error pattern that bloats the database.
 *
 * Deep abandonment (reaching the gateway, then leaving) is deliberately
 * counted by neither. That is an ordinary customer changing their mind, and
 * charging them a cooldown for it costs sales and prevents nothing.
 */
class PaymentBackoffManager
{
    public const SIGNAL_DECLINE = 'declines';

    public const SIGNAL_NO_SHOW = 'no_shows';

    public const TIER_TRUSTED = 'trusted';

    public const TIER_KNOWN = 'known';

    public const TIER_GUEST = 'guest';

    /** How far back either counter looks. */
    private const WINDOW_HOURS = 2;

    /**
     * Cooldown after N declines. Unchanged from the original schedule: it was
     * always a sensible curve for genuine declines, it was simply being fed
     * the wrong events.
     *
     * @var array<int, int>
     */
    private const DECLINE_SCHEDULE = [3 => 30, 4 => 60, 5 => 300, 6 => 900];

    /**
     * Cooldown after N no-shows. Deliberately more forgiving than declines:
     * opening checkout repeatedly is annoying to the database but is not, on
     * its own, evidence of anything but indecision.
     *
     * @var array<int, int>
     */
    private const NO_SHOW_SCHEDULE = [6 => 30, 8 => 60, 10 => 300];

    /**
     * Allowance adjustments per trust band. A customer who has paid before has
     * proved they are real, so they get a longer leash and never a punishing
     * wait. Guests are the disposable-identity end of the range and get less
     * room on the no-show counter, which is the one they abuse.
     *
     * @var array<string, array{decline_bonus: int, no_show_bonus: int, max_wait: int}>
     */
    private const TIERS = [
        self::TIER_TRUSTED => ['decline_bonus' => 3, 'no_show_bonus' => 4, 'max_wait' => 60],
        self::TIER_KNOWN => ['decline_bonus' => 0, 'no_show_bonus' => 0, 'max_wait' => 900],
        self::TIER_GUEST => ['decline_bonus' => 0, 'no_show_bonus' => -2, 'max_wait' => 900],
    ];

    /**
     * @return array{is_rate_limited: bool, wait_seconds: int, consecutive_fails: int, signal: ?string, tier: string, enforced: bool, declines: int, no_shows: int}
     */
    public function getBackoffStatus(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): array
    {
        $identifier = $this->identifierFor($user, $ip, $deviceUuid);
        $tier = $this->tierFor($user);
        $since = $this->countingFloor($user, $productLine, $identifier, $ip, $deviceUuid);

        $outcomes = $this->relevantOutcomes($user, $productLine, $ip, $deviceUuid, $since);

        $declines = $outcomes->filter(fn ($outcome) => $this->isDecline($outcome));
        $noShows = $outcomes->filter(fn ($outcome) => $this->isNoShow($outcome));

        $verdicts = array_filter([
            $this->evaluate(self::SIGNAL_DECLINE, $declines, self::DECLINE_SCHEDULE, self::TIERS[$tier]['decline_bonus'], self::TIERS[$tier]['max_wait']),
            $this->evaluate(self::SIGNAL_NO_SHOW, $noShows, self::NO_SHOW_SCHEDULE, self::TIERS[$tier]['no_show_bonus'], self::TIERS[$tier]['max_wait']),
        ]);

        // The stricter of the two wins, so neither signal can be hidden by the
        // other being quiet.
        usort($verdicts, fn ($a, $b) => $b['wait_seconds'] <=> $a['wait_seconds']);
        $verdict = $verdicts[0] ?? null;

        $enforced = $this->enforcementEnabled();

        $status = [
            'is_rate_limited' => $verdict !== null && $enforced,
            'wait_seconds' => $verdict['wait_seconds'] ?? 0,
            // Reports the count behind the winning signal, or the decline count
            // when nothing tripped, so callers keep a meaningful number.
            'consecutive_fails' => $verdict['count'] ?? $declines->count(),
            'signal' => $verdict['signal'] ?? null,
            'tier' => $tier,
            'enforced' => $enforced,
            'declines' => $declines->count(),
            'no_shows' => $noShows->count(),
        ];

        if ($verdict !== null) {
            $this->record($identifier, $user, $productLine, $verdict, $tier, $declines->count(), $noShows->count(), $enforced, $ip, $deviceUuid);
        }

        return $status;
    }

    /**
     * Is enforcement live, or are we only observing?
     *
     * Defaults to FALSE. A limiter whose thresholds have never been checked
     * against real traffic should not be turning customers away, so it records
     * for a while first and is switched on deliberately once the numbers say
     * what it would have done.
     */
    public function enforcementEnabled(): bool
    {
        return FeatureFlag::on('progressive_backoff')
            && FeatureFlag::on('payment_backoff_enforce', false);
    }

    public function resetBackoff(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): void
    {
        $identifier = $this->identifierFor($user, $ip, $deviceUuid);

        Cache::put("payment_backoff_reset:{$identifier}:{$productLine}", now(), now()->addHours(self::WINDOW_HOURS));
    }

    private function identifierFor(?User $user, string $ip, ?string $deviceUuid): string
    {
        return $user ? 'user:'.$user->id : 'guest:'.$ip.':'.($deviceUuid ?? 'unknown');
    }

    /**
     * Trust band. One confirmed payment, ever, is enough to move a customer out
     * of the strictest handling: people who have paid you are not the threat.
     */
    private function tierFor(?User $user): string
    {
        // A confirmed payment outranks everything else. Someone who has paid
        // you is not the threat, whether or not they ever opened a full
        // account, so this is checked before the guest test.
        if ($user && PaymentOutcome::where('user_id', $user->id)
            ->where('outcome', OutcomeType::Confirmed->value)
            ->exists()) {
            return self::TIER_TRUSTED;
        }

        // Guest checkout mints a real User row with is_guest = true, so a plain
        // null check never sees an actual guest: they reach here as an
        // authenticated shadow account. Without this, the tightest band would
        // apply to nobody in production while the identity that costs nothing
        // to discard and re-mint quietly received the full signed-in
        // allowance.
        if (! $user || $user->is_guest) {
            return self::TIER_GUEST;
        }

        return self::TIER_KNOWN;
    }

    /**
     * Nothing before this moment counts: the later of the window start, an
     * explicit reset, and the customer's last successful payment.
     */
    private function countingFloor(?User $user, string $productLine, string $identifier, string $ip, ?string $deviceUuid): Carbon
    {
        $floor = now()->subHours(self::WINDOW_HOURS);

        $lastReset = Cache::get("payment_backoff_reset:{$identifier}:{$productLine}");
        if ($lastReset && $lastReset->gt($floor)) {
            $floor = $lastReset;
        }

        $lastSuccess = PaymentOutcome::where('product_line', $productLine)
            ->where('outcome', OutcomeType::Confirmed->value)
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            // Guests are matched on the same identity the failure query uses.
            // Filtering on IP alone here meant a guest's success could fail to
            // clear failures that had been attributed to their device.
            ->unless($user, fn ($query) => $query->whereNull('user_id')->where(function ($inner) use ($ip, $deviceUuid) {
                $inner->where('customer_ip', $ip);
                if ($deviceUuid) {
                    $inner->orWhere('device_uuid', $deviceUuid);
                }
            }))
            ->orderByDesc('created_at')
            ->first();

        if ($lastSuccess && $lastSuccess->created_at->gt($floor)) {
            $floor = $lastSuccess->created_at;
        }

        return $floor;
    }

    /**
     * @return Collection<int, PaymentOutcome>
     */
    private function relevantOutcomes(?User $user, string $productLine, string $ip, ?string $deviceUuid, Carbon $since)
    {
        return PaymentOutcome::where('product_line', $productLine)
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Cancelled->value, OutcomeType::Expired->value])
            ->where('created_at', '>', $since)
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->unless($user, fn ($query) => $query->whereNull('user_id')->where(function ($inner) use ($ip, $deviceUuid) {
                $inner->where('customer_ip', $ip);
                if ($deviceUuid) {
                    $inner->orWhere('device_uuid', $deviceUuid);
                }
            }))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * A submitted payment the gateway refused. The stage is what separates this
     * from someone who never got that far: initialization means no payment
     * method was ever engaged.
     */
    private function isDecline(PaymentOutcome $outcome): bool
    {
        // Compared as enums, not strings: the model casts both columns, so
        // `=== 'failed'` silently matches nothing and every counter reads zero.
        return $outcome->outcome === OutcomeType::Failed
            && $outcome->failure_stage !== FailureStage::Initialization;
    }

    /** Pay was tapped, records were written, no payment was ever attempted. */
    private function isNoShow(PaymentOutcome $outcome): bool
    {
        return $outcome->failure_stage === FailureStage::Initialization;
    }

    /**
     * @param  Collection<int, PaymentOutcome>  $events
     * @param  array<int, int>  $schedule
     * @return array{signal: string, count: int, wait_seconds: int}|null
     */
    private function evaluate(string $signal, $events, array $schedule, int $allowanceBonus, int $maxWait): ?array
    {
        $count = $events->count();

        if ($count === 0) {
            return null;
        }

        // A positive bonus raises every threshold, a negative one lowers them.
        $effective = $count - $allowanceBonus;

        $waitSeconds = 0;
        foreach ($schedule as $threshold => $wait) {
            if ($effective >= $threshold) {
                $waitSeconds = $wait;
            }
        }

        if ($waitSeconds === 0) {
            return null;
        }

        $waitSeconds = min($waitSeconds, $maxWait);

        // Measured FROM the event TO now: Carbon 3 diffs are signed, and the
        // reversed direction returns a negative elapsed, which would make the
        // cooldown grow forever instead of expiring.
        $elapsed = (int) $events->first()->created_at->diffInSeconds(now());
        $remaining = max(0, $waitSeconds - $elapsed);

        if ($remaining <= 0) {
            return null;
        }

        return ['signal' => $signal, 'count' => $count, 'wait_seconds' => $remaining];
    }

    /**
     * @param  array{signal: string, count: int, wait_seconds: int}  $verdict
     */
    private function record(string $identifier, ?User $user, string $productLine, array $verdict, string $tier, int $declines, int $noShows, bool $enforced, string $ip, ?string $deviceUuid): void
    {
        PaymentBackoffEvent::create([
            'identifier' => $identifier,
            'user_id' => $user?->id,
            'product_line' => $productLine,
            'signal' => $verdict['signal'],
            'tier' => $tier,
            'decline_count' => $declines,
            'no_show_count' => $noShows,
            'wait_seconds' => $verdict['wait_seconds'],
            'enforced' => $enforced,
            'customer_ip' => $ip,
            'device_uuid' => $deviceUuid,
        ]);
    }
}
