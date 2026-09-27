<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finishes verifications whose provider callback never arrived.
 *
 * A Smile job is resolved by the callback to /api/webhooks/smileid. When that
 * callback is lost (a deploy mid-flight, a network blip, a signature the SDK
 * could not confirm) the attempt sits `pending` forever: the customer's page
 * says "in review" with nothing coming, and once step-up enforcement is on
 * their payment is held behind it. Nothing in the platform noticed, and the
 * only cure was an admin pressing "check with provider" on one attempt at a
 * time.
 *
 * This asks Smile's job-status API about every attempt still waiting past a
 * grace period and applies the verdict through KycVerdictRecorder, the same
 * path the callback uses, so a reconciled verdict and a callback verdict are
 * indistinguishable.
 *
 * Only `pending` attempts are polled. An `abandoned` attempt was replaced by
 * a newer session, and re-reading its old verdict could walk a customer back
 * from a later decision; late callbacks still land on those through the
 * webhook, which is what abandoned exists for.
 *
 * An attempt still pending past --max-age-days was polled every run for the
 * whole window and never had a verdict, so the capture was never submitted.
 * It is closed as abandoned, the same state a newer session leaves an
 * unfinished one in, and a customer held "in review" only by it is put back
 * so they can start again. Closing is what stops it coming back: it used to
 * be reported as stuck on every run, forever.
 */
class ReconcileKycVerificationsCommand extends Command
{
    /**
     * The polling window in days. kyc:validate reads it too, so the
     * pre-flight fails on exactly the attempts this command should have
     * closed.
     */
    public const MAX_AGE_DAYS = 7;

    protected $signature = 'kyc:reconcile
        {--minutes=10 : Grace period: only attempts waiting longer than this are polled}
        {--max-age-days='.self::MAX_AGE_DAYS.' : Attempts still pending after this are closed as abandoned, never polled}
        {--limit=100 : Most attempts to poll in one run}
        {--dry-run : List what would be polled and closed, and change nothing}';

    protected $description = 'Ask the identity provider about verifications whose callback never arrived, and apply the verdicts.';

    public function handle(SmileKycProvider $provider, KycVerdictRecorder $recorder, OperationalAlerter $alerter): int
    {
        if (! $this->providerConfigured()) {
            $this->warn('Smile ID is not configured (SMILE_PARTNER_ID / SMILE_API_KEY), so there is nothing to reconcile.');

            return self::SUCCESS;
        }

        $graceMinutes = max(1, (int) $this->option('minutes'));
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));
        $limit = max(1, (int) $this->option('limit'));

        $expired = $this->expiredAttempts($provider->key(), $maxAgeDays);

        $due = KycVerification::query()
            ->with('user')
            ->where('provider', $provider->key())
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty() && $expired->isEmpty()) {
            $this->info("No verifications are waiting on a verdict beyond {$graceMinutes} minute(s).");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->describeDryRun($due, $expired, $maxAgeDays);

            return self::SUCCESS;
        }

        $this->closeExpired($expired, $maxAgeDays);

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $approved = 0;
        $rejected = 0;
        $stillProcessing = 0;
        $failed = 0;

        foreach ($due as $attempt) {
            try {
                $verdict = $provider->verdictFromJobStatus($provider->queryJobStatus($attempt));
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('kyc.reconcile_failed', [
                    'verification_id' => $attempt->id,
                    'reference' => $attempt->reference,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // Smile is still working on it. Normal for a job minted minutes
            // ago; the next run asks again.
            if ($verdict === null) {
                $stillProcessing++;

                continue;
            }

            $recorder->apply($verdict, $attempt->user, source: 'reconcile');

            $verdict->approved ? $approved++ : $rejected++;
        }

        $this->info(sprintf(
            'Checked %d verification(s): %d verified, %d not verified, %d still processing, %d could not be reached.',
            $due->count(),
            $approved,
            $rejected,
            $stillProcessing,
            $failed,
        ));

        // Every poll failing means Smile is unreachable or the credentials
        // stopped working, not that customers are unlucky.
        if ($failed > 0 && $failed === $due->count()) {
            $alerter->send(
                severity: AlertSeverity::Sev2,
                key: 'kyc:reconcile:provider-unreachable',
                title: 'Identity provider unreachable',
                message: "Smile ID answered none of {$failed} job-status request(s). Verifications finish only when the callback arrives until this clears; check the credentials and Smile's status page.",
                context: ['Attempts' => $failed, 'Runbook check' => 'php artisan kyc:validate'],
                runbook: 'docs/runbooks/logs.md',
            );
        }

        return self::SUCCESS;
    }

    /**
     * Attempts still pending past the polling window. Abandoned attempts are
     * already closed and never count.
     *
     * @return Collection<int, KycVerification>
     */
    private function expiredAttempts(string $providerKey, int $maxAgeDays): Collection
    {
        return KycVerification::query()
            ->where('provider', $providerKey)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays($maxAgeDays))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, KycVerification>  $due
     * @param  Collection<int, KycVerification>  $expired
     */
    private function describeDryRun(Collection $due, Collection $expired, int $maxAgeDays): void
    {
        if ($due->isNotEmpty()) {
            $this->warn("Dry run: {$due->count()} verification(s) would be checked with Smile ID. Nothing was changed.");
            $this->attemptTable($due);
        }

        if ($expired->isNotEmpty()) {
            $this->warn("Dry run: {$expired->count()} verification(s) pending over {$maxAgeDays} day(s) would be closed as abandoned. Nothing was changed.");
            $this->attemptTable($expired);
        }
    }

    /**
     * @param  Collection<int, KycVerification>  $attempts
     */
    private function attemptTable(Collection $attempts): void
    {
        $this->table(
            ['Attempt', 'Reference', 'Customer', 'Product', 'Waiting since'],
            $attempts->map(fn (KycVerification $v) => [
                $v->id,
                $v->reference,
                $v->user_id,
                $v->productLabel(),
                $v->created_at?->diffForHumans(),
            ])->all(),
        );
    }

    /**
     * Close attempts the provider never had a verdict for across the whole
     * polling window. That is a capture the customer never submitted, which
     * is routine, so it is logged once per run rather than alerted: the rows
     * are closed and cannot come back. A verdict that does turn up late still
     * lands on an abandoned attempt through the webhook.
     *
     * @param  Collection<int, KycVerification>  $expired
     */
    private function closeExpired(Collection $expired, int $maxAgeDays): void
    {
        $closed = [];
        $reopened = [];

        foreach ($expired as $attempt) {
            DB::transaction(function () use ($attempt, &$closed, &$reopened): void {
                // A verdict landing between the read and this write wins:
                // only a row that is still pending is closed.
                $closedNow = KycVerification::query()
                    ->whereKey($attempt->getKey())
                    ->where('status', 'pending')
                    ->update(['status' => 'abandoned']) === 1;

                if (! $closedNow) {
                    return;
                }

                $closed[] = $attempt->getKey();

                if ($this->reopenCustomer($attempt)) {
                    $reopened[] = $attempt->user_id;
                }
            });
        }

        if ($closed === []) {
            return;
        }

        $this->warn(sprintf(
            'Closed %d verification(s) pending over %d day(s) as abandoned; %d customer(s) can start verification again.',
            count($closed),
            $maxAgeDays,
            count($reopened),
        ));

        Log::info('kyc.reconcile_closed_unsubmitted', [
            'closed' => count($closed),
            'max_age_days' => $maxAgeDays,
            'verification_ids' => array_slice($closed, 0, 50),
            'reopened_user_ids' => array_slice($reopened, 0, 50),
        ]);
    }

    /**
     * Put the customer back where they stood before the closed attempt, when
     * it is the only thing holding them "in review". While their status reads
     * pending, Verify now and the checkout step-up both refuse to start a new
     * session, so without this they could never try again. Verified and
     * rejected customers are never touched.
     *
     * The status they had before is not stored. Verify now starts from one
     * of two: rejected when their last decided attempt was a rejection,
     * unsubmitted otherwise.
     */
    private function reopenCustomer(KycVerification $attempt): bool
    {
        $user = User::query()->lockForUpdate()->find($attempt->user_id);

        if ($user === null || $user->kyc_status !== 'pending') {
            return false;
        }

        $anotherLiveAttempt = KycVerification::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'pending')
            ->exists();

        if ($anotherLiveAttempt) {
            return false;
        }

        $lastDecided = KycVerification::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', ['approved', 'rejected'])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->value('status');

        $user->update(['kyc_status' => $lastDecided === 'rejected' ? 'rejected' : 'unsubmitted']);

        return true;
    }

    private function providerConfigured(): bool
    {
        return trim((string) config('services.smileid.partner_id')) !== ''
            && trim((string) config('services.smileid.api_key')) !== '';
    }
}
