<?php

namespace App\Console\Commands;

use App\Domain\Kyc\Providers\SmileKycProvider;
use App\Domain\Kyc\Services\KycSelfServeEligibility;
use App\Domain\Kyc\Services\KycTierService;
use App\Models\KycVerification;
use App\Models\SiteSetting;
use App\Models\SpendingLimitEvent;
use App\Models\User;
use App\Support\FeatureFlag;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/**
 * The pre-flight before identity verification is switched on.
 *
 * `features.kyc_verification_enabled` turning on means real customers are
 * sent to a real provider and charged for, so everything the flow depends on
 * is checked first from one command: credentials, the callback Smile will
 * post to, the result codes that decide a pass, the settings the dials read,
 * the reconciler that rescues a lost callback, and the state the accounts
 * are in today.
 *
 * Read-only and safe to run in production: no provider call, no write. It
 * proves the wiring is coherent, not that Smile accepts the credentials,
 * which only a real job can prove (one sandbox job did, 2026-09-15).
 */
class ValidateKycPlatformCommand extends Command
{
    protected $signature = 'kyc:validate {--json : Emit the raw result for dashboards and probes}';

    protected $description = 'Check everything identity verification depends on before the switch is turned on.';

    /** @var array<string, array<int, array{status: string, label: string, detail: ?string}>> */
    private array $results = [];

    public function handle(SmileKycProvider $provider, KycTierService $tiers, KycSelfServeEligibility $eligibility): int
    {
        $this->checkCredentials();
        $this->checkCallback();
        $this->checkResultCodes();
        $this->checkSettings($tiers, $eligibility);
        $this->checkReconciler();
        $this->checkAccounts();

        return $this->render();
    }

    // ───────────────────────────────────────────── credentials

    private function checkCredentials(): void
    {
        $section = 'Provider credentials';

        foreach (['partner_id' => 'SMILE_PARTNER_ID', 'api_key' => 'SMILE_API_KEY'] as $key => $env) {
            $value = trim((string) config("services.smileid.{$key}"));
            $this->record($section, $value !== '', "{$env} is set", $value === '' ? 'Verification cannot start without it.' : null);
        }

        $server = (int) config('services.smileid.server', 0);
        $isProduction = app()->isProduction();

        // Sandbox approves anything (a random selfie passed as a passport in
        // the 2026-09-15 run), so pointing production at it would verify
        // every customer who tried.
        $this->record(
            $section,
            ! $isProduction || $server === 1,
            $isProduction ? 'SMILE_SERVER is production (1)' : 'SMILE_SERVER is '.($server === 1 ? 'production (1)' : 'sandbox (0)'),
            $isProduction && $server !== 1 ? 'Production is pointed at the Smile sandbox, which approves anything.' : null,
        );

        $this->record($section, true, 'Credentials are checked for presence, not acceptance', 'Only a real job proves Smile accepts them; one sandbox job did on 2026-09-15.');
    }

    // ───────────────────────────────────────────── callback

    private function checkCallback(): void
    {
        $section = 'Result callback';

        $callback = trim((string) config('services.smileid.callback_url'));
        $this->record($section, $callback !== '', 'SMILE_CALLBACK_URL is set', $callback === '' ? 'Without it Smile has nowhere to post a verdict and every attempt waits forever.' : null);

        if ($callback === '') {
            return;
        }

        $this->record($section, str_starts_with($callback, 'https://'), 'Callback is https', str_starts_with($callback, 'https://') ? null : "Smile posts signed results; {$callback} is not https.");

        $expected = route('api.webhooks.smileid');
        $this->record(
            $section,
            rtrim($callback, '/') === rtrim($expected, '/'),
            'Callback points at the route that handles it',
            rtrim($callback, '/') === rtrim($expected, '/') ? null : "Configured {$callback}, this app answers at {$expected}.",
        );

        $this->record($section, Route::has('api.webhooks.smileid'), 'The callback route is registered');
    }

    // ───────────────────────────────────────────── result codes

    private function checkResultCodes(): void
    {
        $section = 'Pass codes';

        $codes = (array) config('services.smileid.approved_result_codes', []);
        $doc = (array) ($codes['doc_verification'] ?? []);

        // Every Smile product reports success with its own code. An empty
        // list means every genuine pass of that product reads as rejected.
        $this->record($section, $doc !== [], 'Document Verification has pass codes', $doc === [] ? 'Every document pass would be recorded as a rejection.' : 'Accepts '.implode(', ', $doc).'.');

        $biometricCountries = (array) config('services.smileid.biometric_countries', []);
        $biometric = (array) ($codes['biometric_kyc'] ?? []);

        if ($biometricCountries === []) {
            $this->record($section, true, 'Biometric routing is off', 'Every customer gets Document Verification; biometric codes are unused.');

            return;
        }

        $this->record(
            $section,
            $biometric !== [],
            'Biometric KYC has pass codes',
            $biometric === []
                ? 'Biometric routing is on for '.implode(', ', $biometricCountries).' and every pass would read as a rejection.'
                : 'Accepts '.implode(', ', $biometric).'. Confirm these against a real biometric job before trusting them.',
        );
    }

    // ───────────────────────────────────────────── settings

    private function checkSettings(KycTierService $tiers, KycSelfServeEligibility $eligibility): void
    {
        $section = 'Settings and dials';

        $rows = [
            'features.kyc_verification_enabled',
            'features.kyc_enforce_enabled',
            'features.kyc_tier2_threshold_usd',
            'features.kyc_cumulative_threshold_usd',
            KycSelfServeEligibility::MIN_TRANSACTIONS_KEY,
            KycSelfServeEligibility::MIN_TRANSACTION_USD_KEY,
        ];

        foreach ($rows as $key) {
            $exists = SiteSetting::query()->where('key', $key)->exists();
            $this->record($section, $exists, "{$key} exists", $exists ? null : 'Missing row: the admin Settings page cannot tune it and the code falls back to its default.');
        }

        $single = $tiers->singleThresholdUsd();
        $cumulative = $tiers->cumulativeThresholdUsd();
        $this->record($section, $single > 0, 'Single-transaction dial is set', 'Asks for verification at $'.number_format($single, 2).'.');
        $this->record($section, $cumulative > 0, 'Cumulative dial is set', 'Asks for verification at $'.number_format($cumulative, 2).' of money in over '.$tiers->cumulativeWindowDays().' days.');

        // One ladder: the standard monthly cap IS the cumulative dial, so a
        // customer is never refused by a cap without being offered the
        // verification that lifts it.
        $monthlyCap = SiteSetting::get('features.kyc_cumulative_threshold_usd');
        $this->record($section, is_numeric($monthlyCap), 'The standard monthly cap and the cumulative dial are one row', 'Both read features.kyc_cumulative_threshold_usd.');

        $this->record(
            $section,
            true,
            'Self-serve needs '.$eligibility->minTransactions().' transaction(s) of at least $'.number_format($eligibility->minTransactionUsd(), 2),
            $eligibility->minTransactions() === 0 ? 'Set to 0: anyone may start a paid check.' : null,
        );

        // Go-live order: refusing a payment with "verify to raise your limit"
        // while verification is unavailable is a dead end.
        $verificationOn = FeatureFlag::on('features.kyc_verification_enabled', false);
        $limitsEnforced = FeatureFlag::on('features.spending_limits_enforce_enabled', false);
        $this->record(
            $section,
            ! $limitsEnforced || $verificationOn,
            'Spending limits are not enforced ahead of verification',
            $limitsEnforced && ! $verificationOn ? 'Limits refuse payments and tell customers to verify, but verification is switched off.' : null,
        );

        // Shadow is only worth something if somebody reads it before the flip.
        if (! $limitsEnforced) {
            $observed = SpendingLimitEvent::query()->where('enforced', false)->count();
            $this->record(
                $section,
                true,
                $observed === 0
                    ? 'No customer has reached a spending cap yet'
                    : "{$observed} customer action(s) reached a cap and were let through",
                $observed === 0 ? null : 'Read them with `php artisan limits:report` before enforcing the caps.',
            );
        }

        $stepUpEnforced = FeatureFlag::on('features.kyc_enforce_enabled', false);
        $this->record(
            $section,
            ! $stepUpEnforced || $verificationOn,
            'Step-up is not enforced ahead of verification',
            $stepUpEnforced && ! $verificationOn ? 'Step-ups are enforced while verification is off; they are logged and let through.' : null,
        );

        $this->record($section, true, 'Verification switch is '.($verificationOn ? 'ON' : 'OFF'), $verificationOn ? 'Customers can start verification and every check is billed.' : 'Verify now answers "unavailable"; nothing reaches the provider.');
    }

    // ───────────────────────────────────────────── reconciler

    private function checkReconciler(): void
    {
        $section = 'Lost-callback recovery';

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'kyc:reconcile'));

        $this->record($section, $scheduled, 'kyc:reconcile is scheduled', $scheduled ? null : 'A lost callback would leave the customer in review forever.');
    }

    // ───────────────────────────────────────────── accounts

    private function checkAccounts(): void
    {
        $section = 'Accounts today';

        // Only a pending attempt past the polling window is a failure:
        // kyc:reconcile closes those, so one left means it is not running.
        // A younger pending attempt is in flight, and an abandoned one is
        // already closed; both are shown, neither blocks.
        $maxAgeDays = ReconcileKycVerificationsCommand::MAX_AGE_DAYS;
        $cutoff = now()->subDays($maxAgeDays);

        $overdue = KycVerification::query()->where('status', 'pending')->where('created_at', '<', $cutoff);
        $overdueCount = (clone $overdue)->count();

        $this->record(
            $section,
            $overdueCount === 0,
            $overdueCount === 0
                ? "No verification has been pending over {$maxAgeDays} day(s)"
                : "{$overdueCount} verification(s) pending over {$maxAgeDays} day(s)",
            $overdueCount === 0 ? null : 'Oldest since '.$overdue->min('created_at').'. kyc:reconcile closes these; run it, then check again.',
        );

        $inFlight = KycVerification::query()->where('status', 'pending')->where('created_at', '>=', $cutoff)->count();
        $this->record(
            $section,
            true,
            $inFlight === 0 ? 'No verification is waiting on a verdict' : "{$inFlight} verification(s) waiting on a verdict",
            $inFlight === 0 ? null : 'In flight. kyc:reconcile asks the provider about these on every run.',
        );

        $abandoned = KycVerification::query()->where('status', 'abandoned')->count();
        $this->record(
            $section,
            true,
            "{$abandoned} attempt(s) closed without a verdict",
            $abandoned === 0 ? null : 'A capture the customer never submitted, or one a newer attempt replaced. A late verdict still lands on it.',
        );

        $verified = User::query()->where('kyc_status', 'verified')->count();
        $withoutAttempt = User::query()
            ->where('kyc_status', 'verified')
            ->whereDoesntHave('kycVerifications', fn ($query) => $query->where('status', 'approved'))
            ->count();

        $this->record($section, true, "{$verified} account(s) read verified");

        // Accounts set verified by an admin with no provider pass behind
        // them: fine if deliberate, worth knowing before enforcement starts.
        $this->record(
            $section,
            true,
            $withoutAttempt === 0 ? 'Every verified account has a provider pass behind it' : "{$withoutAttempt} verified account(s) have no provider pass",
            $withoutAttempt === 0 ? null : 'Set by an admin rather than the provider. Confirm each is intended before enforcing.',
        );
    }

    // ───────────────────────────────────────────── output

    private function record(string $section, bool $passed, string $label, ?string $detail = null): void
    {
        $this->results[$section][] = [
            'status' => $passed ? 'pass' : 'fail',
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function render(): int
    {
        $failed = collect($this->results)->flatten(1)->where('status', 'fail');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $failed->isEmpty() ? 'passed' : 'failed',
                'failures' => $failed->pluck('label')->values(),
                'sections' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>Identity verification pre-flight</>');
        $this->line('  ─────────────────────────────────────────────');

        foreach ($this->results as $section => $checks) {
            $sectionFailed = collect($checks)->where('status', 'fail');

            $this->newLine();
            $this->line('  '.($sectionFailed->isEmpty() ? '<fg=green>✓</>' : '<fg=red>✗</>')." <options=bold>{$section}</>");

            foreach ($checks as $check) {
                $mark = $check['status'] === 'pass' ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line("      {$mark} {$check['label']}");

                if ($check['detail'] !== null) {
                    $this->line("        <fg=yellow>↳ {$check['detail']}</>");
                }
            }
        }

        $total = collect($this->results)->flatten(1)->count();

        $this->newLine();
        $this->line('  ─────────────────────────────────────────────');
        $this->line('  Checks passed: <fg=green>'.($total - $failed->count()).'</>  failed: '.($failed->isEmpty() ? '0' : '<fg=red>'.$failed->count().'</>'));
        $this->newLine();

        if ($failed->isEmpty()) {
            $this->info('Nothing here blocks switching verification on.');

            return self::SUCCESS;
        }

        $this->error('Fix the failures above before switching verification on.');

        return self::FAILURE;
    }
}
