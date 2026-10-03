<?php

namespace App\Domain\PaymentInsights\Console;

use App\Domain\Payment\Support\GatewayFailure;
use App\Domain\PaymentInsights\Enums\OutcomeType;
use App\Domain\PaymentInsights\Services\FailureReasonClassifier;
use App\Models\PaymentOutcome;
use Illuminate\Console\Command;

class ReclassifyPaymentOutcomesCommand extends Command
{
    protected $signature = 'insights:reclassify
        {--days= : Only reclassify outcomes recorded in the last N days}
        {--dry-run : Report what would change without saving anything}';

    protected $description = 'Re-grade historical payment failures with the current classifier, using the raw evidence stored in each outcome\'s metadata.';

    /**
     * Historical outcomes were classified by an earlier, coarser classifier
     * that collapsed most gateway verdicts into unknown_error. The raw
     * evidence (raw_reason, failure_details, provider payload) was stored at
     * record time, so the upgraded taxonomy can re-grade history honestly.
     *
     * Idempotent by construction: re-running grades the same evidence with
     * the same tables and writes the same verdicts. The original failure
     * stage is preserved; it encoded the session's state at failure time,
     * which no later classifier can know better than the moment did.
     */
    public function handle(FailureReasonClassifier $classifier): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $this->backfillPaymentMethods($dryRun, $days);
        $this->clearSuccessfulOutcomes($dryRun, $days);

        $query = PaymentOutcome::query()
            ->whereIn('outcome', [OutcomeType::Failed->value, OutcomeType::Expired->value, OutcomeType::Cancelled->value])
            ->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No unsuccessful outcomes in range; nothing to reclassify.');

            return self::SUCCESS;
        }

        $before = [];
        $after = [];
        $changed = 0;

        $query->chunkById(500, function ($outcomes) use ($classifier, $dryRun, &$before, &$after, &$changed) {
            foreach ($outcomes as $outcome) {
                $oldCode = (string) ($outcome->failure_reason_code ?? 'unknown_error');
                $before[$oldCode] = ($before[$oldCode] ?? 0) + 1;

                $metadata = $outcome->metadata ?? [];
                $failureDetails = is_array($metadata['failure_details'] ?? null) ? $metadata['failure_details'] : null;
                $rawReason = $metadata['raw_reason'] ?? null;

                // Pre-upgrade rows have no failure_details, but many stored a
                // raw gateway/webhook payload; salvage the verdict from it so
                // history heals wherever evidence survived.
                if ($failureDetails === null) {
                    $payload = $metadata['provider_payload'] ?? null;
                    if (is_array($payload) && $payload !== []) {
                        $failureDetails = match ($outcome->provider) {
                            'nowpayments' => GatewayFailure::fromNowPayments($payload),
                            default => GatewayFailure::fromFlutterwave($payload),
                        };
                        // A salvage that found nothing is not evidence.
                        if ($failureDetails['provider_code'] === null && $failureDetails['provider_message'] === null) {
                            $failureDetails = null;
                        }
                    }
                }

                // Rows recorded before defaults existed: an expired session is
                // an abandonment, a cancelled one a customer decision — not
                // unknowns.
                if ($rawReason === null || $rawReason === 'unknown') {
                    $rawReason = match ($outcome->outcome) {
                        OutcomeType::Expired => 'session_expired',
                        OutcomeType::Cancelled => 'user cancelled',
                        default => $rawReason ?? 'unknown',
                    };
                }

                $classification = $classifier->classify(
                    (string) ($outcome->provider ?? 'unknown'),
                    (string) $rawReason,
                    'processing', // stage is preserved below; this input only affects it
                    $failureDetails,
                );

                $newCode = $classification['code'];
                $after[$newCode] = ($after[$newCode] ?? 0) + 1;

                $dirty = $newCode !== $oldCode
                    || $outcome->failure_category !== $classification['category']
                    || $outcome->retryable !== $classification['retryable']
                    || $outcome->provider_code !== $classification['provider_code'];

                if (! $dirty) {
                    continue;
                }

                $changed++;

                if (! $dryRun) {
                    $outcome->forceFill([
                        'failure_category' => $classification['category']->value,
                        'failure_reason_code' => $newCode,
                        'provider_code' => $classification['provider_code'],
                        'retryable' => $classification['retryable'],
                        // failure_stage deliberately untouched.
                    ])->save();
                }
            }
        });

        $this->line('');
        $this->components->twoColumnDetail('<fg=cyan>Outcomes examined</>', number_format($total));
        $this->components->twoColumnDetail($dryRun ? '<fg=yellow>Would change (dry run)</>' : '<fg=green>Changed</>', number_format($changed));

        $this->line('');
        $this->info('Reason codes, before → after:');
        $codes = collect(array_keys($before + $after))->sort()->values();
        $rows = $codes->map(fn (string $code) => [
            $code,
            number_format($before[$code] ?? 0),
            number_format($after[$code] ?? 0),
        ])->all();
        $this->table(['Code', 'Before', 'After'], $rows);

        if ($dryRun) {
            $this->warn('Dry run: nothing was saved. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Successful payments recorded before the outcome job stopped grading them
     * carry a failure verdict built from the placeholder reason 'unknown'.
     * There was no failure to describe, so the fields are emptied.
     */
    private function clearSuccessfulOutcomes(bool $dryRun, ?int $days): void
    {
        $query = PaymentOutcome::query()
            ->where('outcome', OutcomeType::Confirmed->value)
            ->where(fn ($q) => $q->whereNotNull('failure_reason_code')
                ->orWhereNotNull('failure_category')
                ->orWhereNotNull('failure_stage'))
            ->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)));

        $count = (clone $query)->count();

        if (! $dryRun && $count > 0) {
            $query->update([
                'failure_stage' => null,
                'failure_category' => null,
                'failure_reason_code' => null,
                'provider_code' => null,
                'retryable' => null,
                'updated_at' => now(),
            ]);
        }

        $this->components->twoColumnDetail(
            $dryRun ? '<fg=yellow>Successful payments carrying a failure code (dry run)</>' : '<fg=green>Successful payments cleared of a failure code</>',
            number_format($count),
        );
    }

    /**
     * Outcomes recorded before the method was read from the attempt carry a
     * null payment_method, which left the "By Payment Method" table empty for
     * as long as the platform has run. The attempt still knows; copy it over.
     * Every outcome, confirmed ones included, since the table measures both.
     */
    private function backfillPaymentMethods(bool $dryRun, ?int $days): void
    {
        $missing = PaymentOutcome::query()
            ->whereNull('payment_method')
            ->when($days, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->with('paymentSession.paymentAttempt')
            ->orderBy('id');

        $examined = 0;
        $filled = 0;

        $missing->chunkById(500, function ($outcomes) use ($dryRun, &$examined, &$filled) {
            foreach ($outcomes as $outcome) {
                $examined++;
                $method = $outcome->paymentSession?->paymentAttempt?->payment_method;

                if ($method === null) {
                    continue;
                }

                $filled++;

                if (! $dryRun) {
                    $outcome->forceFill(['payment_method' => $method])->save();
                }
            }
        });

        $this->components->twoColumnDetail('<fg=cyan>Outcomes missing a payment method</>', number_format($examined));
        $this->components->twoColumnDetail($dryRun ? '<fg=yellow>Would fill from the attempt (dry run)</>' : '<fg=green>Filled from the attempt</>', number_format($filled));
    }
}
