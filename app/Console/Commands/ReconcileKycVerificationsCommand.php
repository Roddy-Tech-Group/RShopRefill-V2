<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycVerdictRecorder;
use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use App\Models\KycVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
 * Attempts older than --max-age-days are never polled (Smile has long since
 * finished or forgotten them) and are reported as stuck so a human closes
 * them out.
 */
class ReconcileKycVerificationsCommand extends Command
{
    protected $signature = 'kyc:reconcile
        {--minutes=10 : Grace period: only attempts waiting longer than this are polled}
        {--max-age-days=7 : Attempts older than this are reported as stuck, never polled}
        {--limit=100 : Most attempts to poll in one run}
        {--dry-run : List what would be polled and change nothing}';

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

        $stuck = $this->stuckAttempts($maxAgeDays);

        $due = KycVerification::query()
            ->with('user')
            ->where('provider', $provider->key())
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subMinutes($graceMinutes))
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($due->isEmpty() && $stuck->isEmpty()) {
            $this->info("No verifications are waiting on a verdict beyond {$graceMinutes} minute(s).");

            return self::SUCCESS;
        }

        $this->reportStuck($stuck, $maxAgeDays, $alerter);

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry run: {$due->count()} verification(s) would be checked with Smile ID. Nothing was changed.");
            $this->table(
                ['Attempt', 'Reference', 'Customer', 'Product', 'Waiting since'],
                $due->map(fn (KycVerification $v) => [
                    $v->id,
                    $v->reference,
                    $v->user_id,
                    $v->productLabel(),
                    $v->created_at?->diffForHumans(),
                ])->all(),
            );

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
     * Attempts past the polling window with no verdict. Smile will not have
     * an answer any more, so these need a person: reconcile by hand from the
     * admin page, or set the customer's status directly.
     *
     * @return Collection<int, KycVerification>
     */
    private function stuckAttempts(int $maxAgeDays): Collection
    {
        return KycVerification::query()
            ->whereIn('status', ['pending', 'abandoned'])
            ->where('created_at', '<', now()->subDays($maxAgeDays))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, KycVerification>  $stuck
     */
    private function reportStuck(Collection $stuck, int $maxAgeDays, OperationalAlerter $alerter): void
    {
        if ($stuck->isEmpty()) {
            return;
        }

        $this->warn("{$stuck->count()} verification(s) have been waiting over {$maxAgeDays} day(s) and are past the point Smile ID will answer for them.");

        foreach ($stuck->take(10) as $attempt) {
            $this->line("  - attempt {$attempt->id} (customer {$attempt->user_id}), {$attempt->productLabel()}, since ".($attempt->created_at?->toDateString() ?? 'unknown'));
        }

        $alerter->send(
            severity: AlertSeverity::Sev2,
            key: 'kyc:reconcile:stuck',
            title: 'Verifications stuck without a verdict',
            message: sprintf(
                '%d identity verification(s) have waited over %d days with no verdict. The customer sees "in review" with nothing coming, and under step-up enforcement their payments are held. Resolve each from the admin verification page, or set the customer\'s status by hand.',
                $stuck->count(),
                $maxAgeDays,
            ),
            context: [
                'Oldest' => $stuck->first()?->created_at?->toDateString(),
                'Customers' => $stuck->take(10)->pluck('user_id')->implode(', '),
            ],
            runbook: 'docs/runbooks/logs.md',
        );
    }

    private function providerConfigured(): bool
    {
        return trim((string) config('services.smileid.partner_id')) !== ''
            && trim((string) config('services.smileid.api_key')) !== '';
    }
}
