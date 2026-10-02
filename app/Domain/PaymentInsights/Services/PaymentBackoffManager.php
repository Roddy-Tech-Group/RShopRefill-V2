<?php

namespace App\Domain\PaymentInsights\Services;

use App\Domain\PaymentInsights\Support\DeclineVerdict;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\PaymentBackoffEvent;
use App\Models\PaymentDecline;
use App\Models\PaymentSession;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Decides whether a customer may start another payment.
 *
 * Two rules, deliberately separate.
 *
 *   Failed attempts  Every payment that did not complete counts, on every rail
 *                    that brings new money in: a refused card, a rejected
 *                    mobile money prompt, a bank transfer or crypto address
 *                    left to expire, a popup closed, a compliance block. Each
 *                    refused card inside one popup is its own attempt. Nothing
 *                    is weighted and nothing is excused: every one of them
 *                    writes rows, and trying the same thing again straight
 *                    away does not fix it.
 *
 *                      3 in a row   a 60 second pause
 *                      4 or more    a 5 minute pause after each one
 *
 *   Card testing     The only thing that can lock. Five DIFFERENT cards that
 *                    the gateway refused with a fraud-class verdict, inside
 *                    the window. No exceptions: not for an account that has
 *                    paid us before, not for an old account, not for a
 *                    verified identity. A tester can get lucky with one card,
 *                    age an account or pass verification, and rotating five
 *                    cards the gateway calls fraud is the behaviour, whoever
 *                    is doing it. That closes new payments on the device for
 *                    six hours, raises a Telegram alert with the card timeline
 *                    and a one-tap lift, and follows the device to any new
 *                    account opened on it. A real customer caught by it
 *                    contacts support and is lifted.
 *
 * Both count inside a rolling two hour window. A successful payment starts the
 * failed attempts again, so a customer who finally pays is never left waiting.
 * It does NOT erase the fraud-refused cards: one lucky card must not buy a
 * fresh start. Only time, or an admin lifting a lock, clears those.
 *
 * What stays open: paying from a balance the customer already holds. A pause
 * or a lock stops new money coming in. It never stops someone spending money
 * already in their wallet, because spending a balance cannot test a card, and
 * freezing funds is a human decision with its own tool (the admin wallet hold).
 *
 * Counts come from the payment sessions themselves, which are written the
 * moment a payment ends, rather than from the insights outcomes, which are
 * written on a queue and can lag a fast retry.
 *
 * Devices are the key, not IPs: carrier networks in Cameroon and Nigeria put
 * hundreds of customers behind one address, so the IP is only used for an
 * anonymous caller with no device fingerprint, and never for a signed-in one.
 *
 * The limiter fails open: PaymentSessionService::assertNotRateLimited() reports
 * and lets the payment through if anything in here throws.
 */
class PaymentBackoffManager
{
    public const SIGNAL_FAILED_ATTEMPTS = 'failed_attempts';

    public const SIGNAL_CARD_TESTING = 'card_testing';

    public const TIER_TRUSTED = 'trusted';

    public const TIER_KNOWN = 'known';

    public const TIER_GUEST = 'guest';

    public const STEP_NONE = 0;

    public const STEP_PAUSE = 2;

    public const STEP_HOLD = 3;

    public const STEP_LOCK = 4;

    /** How far back the counters look. */
    public const WINDOW_HOURS = 2;

    /** Failed attempts in a row that earn the first pause. */
    public const PAUSE_AFTER_ATTEMPTS = 3;

    public const PAUSE_SECONDS = 60;

    /** From this attempt on, every further failure earns the longer pause. */
    public const HOLD_AFTER_ATTEMPTS = 4;

    public const HOLD_SECONDS = 300;

    /** Different cards refused with a fraud-class verdict that reach the lock. */
    public const LOCK_FRAUD_CARDS = 5;

    public const LOCK_SECONDS = 21600;

    /**
     * Different fraud-refused cards at which recovery emails stop and the
     * dashboard marks the customer as suspected testing. Lower than the lock,
     * because it only stops us inviting them back; it holds nobody.
     */
    public const SUSPECTED_TESTING_FRAUD_CARDS = 3;

    /** Session states that mean the payment ended without the money arriving. */
    private const FAILED_SESSION_STATUSES = ['failed', 'expired', 'cancelled'];

    /**
     * Rails that spend a balance the customer already holds. Never counted and
     * never held: nothing about them can test a card.
     */
    public const BALANCE_PROVIDERS = ['wallet'];

    public function __construct(private readonly OperationalAlerter $alerter) {}

    /**
     * The pre-flight decision. Records the verdict (whether or not enforcement
     * is on) so shadow mode leaves a trail.
     *
     * @return array{is_rate_limited: bool, wait_seconds: int, consecutive_fails: int, signal: ?string, tier: string, enforced: bool, attempts: int, refused_cards: int, fraud_cards: int, step: int, locked: bool}
     */
    public function getBackoffStatus(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): array
    {
        return $this->evaluate($user, $productLine, $ip, $deviceUuid, record: true);
    }

    /**
     * The same decision without leaving a trace, for callers that only want to
     * know where an identity stands.
     *
     * @return array{is_rate_limited: bool, wait_seconds: int, consecutive_fails: int, signal: ?string, tier: string, enforced: bool, attempts: int, refused_cards: int, fraud_cards: int, step: int, locked: bool}
     */
    public function peek(?User $user, string $productLine, ?string $ip, ?string $deviceUuid = null): array
    {
        return $this->evaluate($user, $productLine, (string) $ip, $deviceUuid, record: false);
    }

    /**
     * Has this identity run enough fraud-refused cards that we should stop
     * inviting them back? Holds nobody; it silences recovery emails and marks
     * the dashboard.
     */
    public function suspectedTesting(?User $user, ?string $ip, ?string $deviceUuid): bool
    {
        $status = $this->peek($user, 'checkout', $ip, $deviceUuid);

        return $status['locked'] || $status['fraud_cards'] >= self::SUSPECTED_TESTING_FRAUD_CARDS;
    }

    /**
     * Is enforcement live, or are we only observing? Defaults to FALSE, so a
     * fresh deployment never turns anyone away until it is switched on.
     */
    public function enforcementEnabled(): bool
    {
        return FeatureFlag::on('progressive_backoff')
            && FeatureFlag::on('payment_backoff_enforce', false);
    }

    /**
     * Start the failed attempt count again from now. Used when a customer
     * arrives through a recovery link: the invitation to retry should not greet
     * them with a pause. Counting is shared across checkout and top-ups, so the
     * reset is too; the product line is accepted for the callers' sake. The
     * fraud-refused cards are left alone, exactly as a successful payment
     * leaves them alone.
     */
    public function resetBackoff(?User $user, string $productLine, string $ip, ?string $deviceUuid = null): void
    {
        $identifier = $this->identifierFor($user, $ip, $deviceUuid);

        Cache::put("payment_backoff_reset:{$identifier}", now(), now()->addHours(self::WINDOW_HOURS));
    }

    /**
     * End a lock early. The event stays on record with who lifted it, and
     * everything that caused it stops counting from this moment, so the same
     * evidence cannot re-lock the customer a second later.
     */
    public function liftLock(PaymentBackoffEvent $event, ?int $adminId = null): void
    {
        if ($event->signal !== self::SIGNAL_CARD_TESTING || $event->lifted_at !== null) {
            return;
        }

        $event->forceFill(['lifted_at' => now(), 'lifted_by' => $adminId])->save();
    }

    /**
     * The lock currently governing this identity, if any.
     */
    public function activeLock(?User $user, ?string $deviceUuid, ?string $ip): ?PaymentBackoffEvent
    {
        return PaymentBackoffEvent::query()
            ->where('signal', self::SIGNAL_CARD_TESTING)
            ->whereNull('lifted_at')
            ->where('created_at', '>', now()->subSeconds(self::LOCK_SECONDS))
            ->where(fn ($query) => $this->scopeToIdentity($query, $user, $deviceUuid, $ip))
            ->latest('id')
            ->first();
    }

    /**
     * @return array{is_rate_limited: bool, wait_seconds: int, consecutive_fails: int, signal: ?string, tier: string, enforced: bool, attempts: int, refused_cards: int, fraud_cards: int, step: int, locked: bool}
     */
    private function evaluate(?User $user, string $productLine, string $ip, ?string $deviceUuid, bool $record): array
    {
        $identifier = $this->identifierFor($user, $ip, $deviceUuid);
        $tier = $this->tierFor($user);
        $enforced = $this->enforcementEnabled();

        $floors = $this->countingFloors($user, $identifier, $ip, $deviceUuid);

        // Every refusal since the fraud floor is evidence for the lock; only
        // those since the attempts floor still count toward a pause.
        $declines = $this->declines($user, $deviceUuid, $ip, $floors['fraud']);
        $recentDeclines = $declines
            ->filter(fn (PaymentDecline $row) => $row->declined_at->gt($floors['attempts']))
            ->values();

        $attempts = $this->failedAttempts($user, $deviceUuid, $ip, $floors['attempts'], $recentDeclines);
        $cards = $this->cards($declines);

        $activeLock = $this->activeLock($user, $deviceUuid, $ip);
        $decision = $activeLock === null
            ? $this->decide($attempts, $cards)
            : ['step' => self::STEP_LOCK, 'verdict' => null];

        $verdict = null;

        if ($activeLock !== null) {
            $remaining = self::LOCK_SECONDS - $this->secondsSince($activeLock->created_at);

            if ($remaining > 0) {
                $verdict = ['signal' => self::SIGNAL_CARD_TESTING, 'wait_seconds' => $remaining, 'existing' => true];
            }
        } elseif ($decision['verdict'] !== null) {
            $verdict = $decision['verdict'] + ['existing' => false];
        }

        $status = [
            'is_rate_limited' => $verdict !== null && $enforced,
            'wait_seconds' => $verdict['wait_seconds'] ?? 0,
            'consecutive_fails' => $attempts['count'],
            'signal' => $verdict['signal'] ?? null,
            'tier' => $tier,
            'enforced' => $enforced,
            'attempts' => $attempts['count'],
            'refused_cards' => $cards['distinct'],
            'fraud_cards' => $cards['fraud'],
            'step' => $decision['step'],
            'locked' => ($verdict['signal'] ?? null) === self::SIGNAL_CARD_TESTING,
        ];

        if ($record && $verdict !== null && ! $verdict['existing'] && ! $this->alreadyRecorded($identifier, $verdict['signal'], $attempts['latest_at'])) {
            $event = $this->record($identifier, $user, $productLine, $verdict, $tier, $attempts['count'], $cards['distinct'], $enforced, $ip, $deviceUuid);

            if ($verdict['signal'] === self::SIGNAL_CARD_TESTING) {
                $this->alertLock($event, $user, $cards, $attempts['count'], $enforced);
            }
        }

        return $status;
    }

    /**
     * Which rule applies, and how long is left to serve. `step` is the rung
     * reached regardless of time elapsed; `verdict` is set only while there is
     * still a wait to serve.
     *
     * @param  array{count: int, latest_at: ?Carbon}  $attempts
     * @param  array{distinct: int, fraud: int, latest_fraud_at: ?Carbon, rows: Collection<int, PaymentDecline>}  $cards
     * @return array{step: int, verdict: ?array{signal: string, wait_seconds: int}}
     */
    private function decide(array $attempts, array $cards): array
    {
        // The lock is judged on cards alone, and only on cards the gateway
        // refused as fraud. A run of failed mobile money prompts, however long,
        // is somebody with no balance, not somebody testing stolen cards. Who
        // the customer is does not enter into it.
        if ($cards['fraud'] >= self::LOCK_FRAUD_CARDS && $cards['latest_fraud_at'] !== null) {
            $remaining = self::LOCK_SECONDS - $this->secondsSince($cards['latest_fraud_at']);

            return [
                'step' => self::STEP_LOCK,
                'verdict' => $remaining > 0
                    ? ['signal' => self::SIGNAL_CARD_TESTING, 'wait_seconds' => $remaining]
                    : null,
            ];
        }

        if ($attempts['latest_at'] === null) {
            return ['step' => self::STEP_NONE, 'verdict' => null];
        }

        $elapsed = $this->secondsSince($attempts['latest_at']);

        foreach ([
            [self::HOLD_AFTER_ATTEMPTS, self::HOLD_SECONDS, self::STEP_HOLD],
            [self::PAUSE_AFTER_ATTEMPTS, self::PAUSE_SECONDS, self::STEP_PAUSE],
        ] as [$threshold, $wait, $step]) {
            if ($attempts['count'] < $threshold) {
                continue;
            }

            $remaining = $wait - $elapsed;

            return [
                'step' => $step,
                'verdict' => $remaining > 0
                    ? ['signal' => self::SIGNAL_FAILED_ATTEMPTS, 'wait_seconds' => $remaining]
                    : null,
            ];
        }

        return ['step' => self::STEP_NONE, 'verdict' => null];
    }

    /**
     * Seconds from a past moment to now, never negative.
     *
     * Carbon 3 diffs are signed, and a timestamp slightly in the future (a
     * gateway clock running ahead of ours) would otherwise produce a negative
     * elapsed time and stretch a 60 second pause, or a six hour lock, beyond
     * its length. A wait can never be longer than the rule says.
     */
    private function secondsSince(Carbon $moment): int
    {
        return max(0, (int) $moment->diffInSeconds(now()));
    }

    private function identifierFor(?User $user, string $ip, ?string $deviceUuid): string
    {
        return $user ? 'user:'.$user->id : 'guest:'.$ip.':'.($deviceUuid ?? 'unknown');
    }

    /**
     * Trust band, recorded on every decision so the report can show who was
     * held. It changes nothing about the rules: the pauses and the lock apply
     * to everyone alike.
     */
    private function tierFor(?User $user): string
    {
        if ($user && ($this->hasEverPaid($user) || $user->isKycVerified())) {
            return self::TIER_TRUSTED;
        }

        // Guest checkout mints a real User row with is_guest = true, so a plain
        // null check never sees an actual guest.
        if (! $user || $user->is_guest) {
            return self::TIER_GUEST;
        }

        return self::TIER_KNOWN;
    }

    private function hasEverPaid(User $user): bool
    {
        return PaymentSession::query()
            ->where('payment_sessions.status', 'confirmed')
            ->whereHas('paymentAttempt', fn (Builder $query) => $query->where('user_id', $user->id))
            ->exists();
    }

    /**
     * Where each count starts. Two floors, because a payment going through
     * proves different things to each rule.
     *
     *   attempts  The later of the window start, a recovery-link reset, the
     *             customer's last successful payment, and the last admin lift.
     *             A customer who finally pays is never left waiting.
     *
     *   fraud     The later of the window start and the last admin lift, and
     *             nothing else. A payment going through proves the customer
     *             can pay; it does not erase the stolen cards they tried right
     *             before it. User 243 ran five fraud-refused cards inside two
     *             hours and escaped the lock only because a payment landed just
     *             before the fifth. A recovery link does not erase them either.
     *             The cards still age out two hours after each was refused.
     *
     * @return array{attempts: Carbon, fraud: Carbon}
     */
    private function countingFloors(?User $user, string $identifier, string $ip, ?string $deviceUuid): array
    {
        $windowStart = now()->subHours(self::WINDOW_HOURS);

        $lastLift = PaymentBackoffEvent::query()
            ->where('signal', self::SIGNAL_CARD_TESTING)
            ->whereNotNull('lifted_at')
            ->where('lifted_at', '>', $windowStart)
            ->where(fn ($query) => $this->scopeToIdentity($query, $user, $deviceUuid, $ip))
            ->max('lifted_at');

        $fraudFloor = $this->later($windowStart, $lastLift);

        $lastReset = Cache::get("payment_backoff_reset:{$identifier}");

        // Read from the session, which is stamped the instant a payment
        // confirms, not from the insights outcome written later on a queue.
        //
        // Only new money landing counts as a success. Spending wallet balance
        // proves nothing about whether a card or a bank account works, and if
        // it wiped the slate, buying something tiny from the wallet would be a
        // free reset between runs of failures.
        $lastSuccess = PaymentSession::query()
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payment_sessions.payment_attempt_id')
            ->where('payment_sessions.status', 'confirmed')
            ->whereNotIn('payment_sessions.provider', self::BALANCE_PROVIDERS)
            ->where(fn ($query) => $this->scopeToIdentity($query, $user, $deviceUuid, $ip, 'payment_attempts.user_id', 'payment_sessions.device_uuid', 'payment_sessions.customer_ip', userOnly: true))
            ->max('payment_sessions.confirmed_at');

        $attemptsFloor = $this->later($this->later($fraudFloor, $lastReset instanceof Carbon ? $lastReset : null), $lastSuccess);

        return ['attempts' => $attemptsFloor, 'fraud' => $fraudFloor];
    }

    /**
     * Every refused charge the gateway told us about for this identity since the
     * floor, oldest first.
     *
     * @return Collection<int, PaymentDecline>
     */
    private function declines(?User $user, ?string $deviceUuid, ?string $ip, Carbon $since): Collection
    {
        return PaymentDecline::query()
            ->where('declined_at', '>', $since)
            ->where(fn ($query) => $this->scopeToIdentity($query, $user, $deviceUuid, $ip))
            ->orderBy('declined_at')
            ->get();
    }

    /**
     * Failed attempts since the floor, on every rail that brings new money in.
     *
     * A session that ended without the money arriving is one attempt, unless
     * the gateway refused several cards inside it, in which case every refused
     * card is its own attempt. Refused charges whose session has not ended yet
     * still count, so a popup still open cannot hide them.
     *
     * @param  Collection<int, PaymentDecline>  $declines
     * @return array{count: int, latest_at: ?Carbon}
     */
    private function failedAttempts(?User $user, ?string $deviceUuid, ?string $ip, Carbon $since, Collection $declines): array
    {
        $sessions = PaymentSession::query()
            ->join('payment_attempts', 'payment_attempts.id', '=', 'payment_sessions.payment_attempt_id')
            ->whereIn('payment_sessions.status', self::FAILED_SESSION_STATUSES)
            ->whereNotIn('payment_sessions.provider', self::BALANCE_PROVIDERS)
            // A session closed because a newer checkout replaced its order is
            // housekeeping, not a payment that failed: the customer changed
            // their cart and is still here. Counting it would pause somebody
            // for editing their basket three times (PaymentSessionService::
            // supersedeSession writes the marker).
            ->whereNull('payment_sessions.metadata->superseded_by')
            ->where('payment_sessions.created_at', '>', $since)
            ->where(fn ($query) => $this->scopeToIdentity($query, $user, $deviceUuid, $ip, 'payment_attempts.user_id', 'payment_sessions.device_uuid', 'payment_sessions.customer_ip'))
            ->get(['payment_sessions.id', 'payment_sessions.created_at', 'payment_sessions.failed_at', 'payment_sessions.updated_at']);

        $declinesBySession = $declines->groupBy(fn (PaymentDecline $row) => (string) $row->payment_session_id);

        $count = 0;
        $latest = null;

        foreach ($sessions as $session) {
            $refused = $declinesBySession->get((string) $session->id);
            $count += max(1, $refused?->count() ?? 0);

            $endedAt = $session->failed_at ?? $session->updated_at ?? $session->created_at;
            $latest = $this->later($latest, $endedAt);
        }

        $sessionIds = $sessions->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($declines as $decline) {
            if (! in_array((string) $decline->payment_session_id, $sessionIds, true)) {
                $count++;
            }

            $latest = $this->later($latest, $decline->declined_at);
        }

        return ['count' => $count, 'latest_at' => $latest];
    }

    /**
     * The cards behind those refusals: how many different ones, and how many of
     * those the gateway refused with a fraud-class verdict.
     *
     * @param  Collection<int, PaymentDecline>  $declines
     * @return array{distinct: int, fraud: int, latest_fraud_at: ?Carbon, rows: Collection<int, PaymentDecline>}
     */
    private function cards(Collection $declines): array
    {
        $withCard = $declines->filter(fn (PaymentDecline $row) => filled($row->card_fingerprint));
        $fraudRows = $withCard->filter(fn (PaymentDecline $row) => $row->verdict_class === DeclineVerdict::CLASS_FRAUD);

        return [
            'distinct' => $withCard->pluck('card_fingerprint')->unique()->count(),
            'fraud' => $fraudRows->pluck('card_fingerprint')->unique()->count(),
            'latest_fraud_at' => $fraudRows->max('declined_at'),
            'rows' => $declines,
        ];
    }

    private function later(?Carbon $current, mixed $candidate): ?Carbon
    {
        if ($candidate === null) {
            return $current;
        }

        $candidate = $candidate instanceof Carbon ? $candidate : Carbon::parse($candidate);

        return $current === null || $candidate->gt($current) ? $candidate : $current;
    }

    /**
     * The identity clause shared by every lookup: the signed-in user, the
     * device fingerprint across any account, and the IP only for an anonymous
     * caller with no device fingerprint to go on.
     *
     * `userOnly` restricts the match to the account itself, for questions that
     * belong to one customer rather than to everyone on a device (a success on
     * someone else's account does not wipe this one's slate).
     */
    private function scopeToIdentity(
        $query,
        ?User $user,
        ?string $deviceUuid,
        ?string $ip,
        string $userColumn = 'user_id',
        string $deviceColumn = 'device_uuid',
        string $ipColumn = 'customer_ip',
        bool $userOnly = false,
    ): void {
        if ($user !== null && $userOnly) {
            $query->where($userColumn, $user->id);

            return;
        }

        $bound = false;

        if ($user !== null) {
            $query->where($userColumn, $user->id);
            $bound = true;
        }

        if ($deviceUuid !== null && $deviceUuid !== '') {
            $bound ? $query->orWhere($deviceColumn, $deviceUuid) : $query->where($deviceColumn, $deviceUuid);
            $bound = true;
        } elseif ($user === null && $ip !== null && $ip !== '') {
            // The IP is a last resort for an anonymous caller with no device,
            // never an addition to a known account. Carrier networks in
            // Cameroon and Nigeria put hundreds of customers behind one
            // address; matching "no device, same IP" for a signed-in customer
            // would count strangers' failures against them, and a lock written
            // without a device would hold everyone on that address.
            $clause = fn ($inner) => $inner->whereNull($deviceColumn)->where($ipColumn, $ip);
            $bound ? $query->orWhere($clause) : $query->where($clause);
            $bound = true;
        }

        if (! $bound) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * A pause is recorded once, not once per tap: an event already written
     * after the latest failed attempt means this hold is on file.
     */
    private function alreadyRecorded(string $identifier, string $signal, ?Carbon $latestAttemptAt): bool
    {
        if ($signal === self::SIGNAL_CARD_TESTING || $latestAttemptAt === null) {
            return false;
        }

        return PaymentBackoffEvent::query()
            ->where('identifier', $identifier)
            ->where('signal', $signal)
            ->where('created_at', '>=', $latestAttemptAt)
            ->exists();
    }

    /**
     * @param  array{signal: string, wait_seconds: int}  $verdict
     */
    private function record(string $identifier, ?User $user, string $productLine, array $verdict, string $tier, int $attempts, int $refusedCards, bool $enforced, string $ip, ?string $deviceUuid): PaymentBackoffEvent
    {
        return PaymentBackoffEvent::create([
            'identifier' => $identifier,
            'user_id' => $user?->id,
            'product_line' => $productLine,
            'signal' => $verdict['signal'],
            'tier' => $tier,
            'attempt_count' => $attempts,
            'decline_count' => $refusedCards,
            'wait_seconds' => $verdict['wait_seconds'],
            'enforced' => $enforced,
            'customer_ip' => $ip,
            'device_uuid' => $deviceUuid,
        ]);
    }

    /**
     * A lock is the one decision a human should hear about the moment it is
     * made: the alert carries the card timeline, the customer page and a link
     * that lifts the lock in one tap if the pattern turns out to be a real
     * customer.
     *
     * Written to be triaged from a phone: who the customer is, whether they
     * have ever paid us (a lock on a paying customer deserves the fastest
     * look), and each refused card with its issuer country, since cards from
     * several countries on one account is the clearest tell.
     *
     * @param  array{distinct: int, fraud: int, latest_fraud_at: ?Carbon, rows: Collection<int, PaymentDecline>}  $cards
     */
    private function alertLock(PaymentBackoffEvent $event, ?User $user, array $cards, int $attempts, bool $enforced): void
    {
        try {
            $timeline = $cards['rows']
                ->filter(fn (PaymentDecline $row) => $row->verdict_class === DeclineVerdict::CLASS_FRAUD)
                ->map(fn (PaymentDecline $row) => trim(sprintf(
                    '%s %s%s %s',
                    $row->declined_at->format('H:i'),
                    $row->card_brand ?: 'card',
                    $row->card_issuer_country ? ' '.$row->card_issuer_country : '',
                    $row->card_fingerprint ? '#'.substr($row->card_fingerprint, 0, 6) : '',
                )).': '.($row->verdict ?: 'declined'))
                ->implode(' | ');

            $context = [
                'user_id' => $user?->id,
                'email' => $user?->email,
                'has_paid_us_before' => $user !== null && $this->hasEverPaid($user) ? 'yes' : 'no',
                'identity_verified' => $user !== null && $user->isKycVerified() ? 'yes' : 'no',
                'fraud_refused_cards' => $cards['fraud'],
                'refused_cards' => $cards['distinct'],
                'failed_attempts' => $attempts,
                'device' => $event->device_uuid,
                'IP' => $event->customer_ip,
                'timeline' => $timeline,
            ];

            if ($user !== null && Route::has('admin.customer')) {
                $context['customer_page'] = route('admin.customer', $user->id);
            }

            if (Route::has('admin.backoff.lift')) {
                $context['lift_lock'] = URL::temporarySignedRoute('admin.backoff.lift', now()->addSeconds(self::LOCK_SECONDS), ['event' => $event->id]);
            }

            $this->alerter->send(
                severity: AlertSeverity::Sev2,
                key: 'backoff:lock:'.$event->identifier,
                title: $enforced ? 'Card testing lock applied' : 'Card testing lock (shadow, not applied)',
                message: sprintf(
                    '%d different cards refused as fraud in the last %d hours, %d failed attempts in total. %s',
                    $cards['fraud'],
                    self::WINDOW_HOURS,
                    $attempts,
                    $enforced
                        ? 'New payments are closed on this device for six hours; wallet balance stays spendable. Lift it from the link below if this is a real customer.'
                        : 'Enforcement is off, so nothing was blocked. This is what the lock would have done.',
                ),
                context: $context,
                runbook: 'docs/runbooks/fraud-attack.md',
            );
        } catch (\Throwable $e) {
            Log::warning('backoff.lock_alert_failed', ['event' => $event->id, 'error' => $e->getMessage()]);
        }
    }
}
