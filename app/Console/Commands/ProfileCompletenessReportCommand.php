<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sizes the profile-completeness gap left by Google sign-in.
 *
 * Google returns a name, an email and a picture, so every account created
 * through it before the completion step shipped has no country, phone or
 * gender. Those accounts were deliberately backfilled as complete so the new
 * requirement could never interrupt a customer who signed up months ago, which
 * means the gap is invisible from the app: this report is how we see it.
 *
 * The numbers decide the campaign. A few hundred dormant accounts justify an
 * email and nothing more; a few hundred ACTIVE buyers justify collecting the
 * details at the next money action, where the country decides KYC routing and
 * the phone keeps one line to one account.
 *
 * STRICTLY READ-ONLY. Every query is an aggregate COUNT - nothing is written,
 * no model is instantiated, no event fires, and no row is ever loaded into
 * memory, so this is safe to run against production at any hour.
 */
class ProfileCompletenessReportCommand extends Command
{
    protected $signature = 'users:profile-completeness';

    protected $description = 'Report how many customer accounts are missing their country, phone or gender.';

    public function handle(): int
    {
        $total = $this->customers()->count();

        if ($total === 0) {
            $this->warn('No customer accounts found.');

            return self::SUCCESS;
        }

        $incomplete = $this->missingAnything($this->customers())->count();

        $this->newLine();
        $this->info('Profile completeness');
        $this->line(str_repeat('-', 52));
        $this->line("Customer accounts (guest shadows excluded): {$total}");
        $this->line("Missing at least one detail: {$incomplete} ({$this->percent($incomplete, $total)})");
        $this->newLine();

        $this->info('Missing, by field');
        $this->table(['Field', 'Missing', 'Share'], [
            $this->fieldRow('country', $total),
            $this->fieldRow('phone', $total),
            $this->fieldRow('gender', $total),
        ]);

        $this->info('Missing, by how they signed up');
        $this->table(['Signed up with', 'Accounts', 'Incomplete', 'Share'], [
            $this->cohortRow('Google', fn (Builder $q) => $q->whereNotNull('google_id')),
            $this->cohortRow('Email & password', fn (Builder $q) => $q->whereNull('google_id')),
        ]);

        // The row that decides how hard the campaign has to push: an incomplete
        // account that has never ordered can be emailed and forgotten, while an
        // incomplete buyer is one the risk and KYC engines will need to read.
        $this->info('Missing, by whether they have ever ordered');
        $this->table(['Cohort', 'Accounts', 'Incomplete', 'Share'], [
            $this->cohortRow('Has ordered', fn (Builder $q) => $q->whereHas('orders')),
            $this->cohortRow('Never ordered', fn (Builder $q) => $q->whereDoesntHave('orders')),
        ]);

        $this->countryFormatCheck();

        return self::SUCCESS;
    }

    /**
     * Real customers only. Legacy guest shadow accounts were never registered,
     * so counting them would overstate the gap.
     */
    private function customers(): Builder
    {
        return User::query()->where(function (Builder $query) {
            $query->where('is_guest', false)->orWhereNull('is_guest');
        });
    }

    /**
     * Missing any one of the three details.
     */
    private function missingAnything(Builder $query): Builder
    {
        return $query->where(function (Builder $inner) {
            foreach (['country', 'phone', 'gender'] as $field) {
                $inner->orWhereNull($field)->orWhere($field, '');
            }
        });
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function fieldRow(string $field, int $total): array
    {
        $missing = $this->customers()
            ->where(fn (Builder $q) => $q->whereNull($field)->orWhere($field, ''))
            ->count();

        return [$field, $missing, $this->percent($missing, $total)];
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return array{0: string, 1: int, 2: int, 3: string}
     */
    private function cohortRow(string $label, callable $scope): array
    {
        $accounts = $scope($this->customers())->count();
        $incomplete = $this->missingAnything($scope($this->customers()))->count();

        return [$label, $accounts, $incomplete, $this->percent($incomplete, max($accounts, 1))];
    }

    /**
     * users.country holds ISO 3166-1 alpha-2 codes. Anything longer is a display
     * name left over from when the profile page wrote those, which the
     * normalization migration should have converted - a non-zero count here
     * means a row it could not map, worth a look.
     */
    private function countryFormatCheck(): void
    {
        $nonIso = $this->customers()
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->whereRaw('LENGTH(country) <> 2')
            ->count();

        if ($nonIso > 0) {
            $this->warn("{$nonIso} account(s) hold a country value that is not a 2-letter ISO code.");

            return;
        }

        $this->line('Country format: every stored value is a 2-letter ISO code.');
    }

    private function percent(int $part, int $whole): string
    {
        return $whole > 0 ? round($part / $whole * 100, 1).'%' : '0%';
    }
}
