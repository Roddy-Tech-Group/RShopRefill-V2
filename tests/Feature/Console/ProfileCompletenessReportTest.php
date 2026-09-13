<?php

namespace Tests\Feature\Console;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The report that sizes the Google profile gap before we design a campaign
 * around it. It must count accurately and, above all, change nothing: it is
 * meant to be run against production.
 */
class ProfileCompletenessReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_accounts_missing_details_without_touching_them(): void
    {
        $complete = User::factory()->create([
            'country' => 'NG',
            'phone' => '+2348031234567',
            'gender' => 'female',
        ]);

        $incomplete = User::factory()->create([
            'google_id' => 'google-1',
            'country' => null,
            'phone' => null,
            'gender' => null,
        ]);

        // The three fields the report actually inspects.
        $snapshot = fn (User $user): array => [
            $user->country,
            $user->phone,
            $user->gender,
        ];

        $before = [$snapshot($complete), $snapshot($incomplete)];

        $this->artisan('users:profile-completeness')
            ->assertExitCode(0);

        // Read-only: every field the report inspects is exactly as it was.
        $this->assertSame($before, [$snapshot($complete->fresh()), $snapshot($incomplete->fresh())]);
    }

    public function test_it_reports_the_headline_counts(): void
    {
        User::factory()->create(['country' => 'NG', 'phone' => '+2348031234567', 'gender' => 'female']);
        User::factory()->count(3)->create(['country' => null, 'phone' => null, 'gender' => null]);

        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('Customer accounts (guest shadows excluded): 4')
            ->expectsOutputToContain('Missing at least one detail: 3')
            ->assertExitCode(0);
    }

    public function test_a_partially_filled_account_still_counts_as_incomplete(): void
    {
        // Country and gender present, phone missing: the campaign still needs
        // this person, so a partial fill must not read as done.
        User::factory()->create(['country' => 'NG', 'phone' => null, 'gender' => 'male']);

        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('Missing at least one detail: 1')
            ->assertExitCode(0);
    }

    public function test_guest_shadow_accounts_are_excluded_from_the_gap(): void
    {
        User::factory()->create(['country' => 'NG', 'phone' => '+2348031234567', 'gender' => 'female']);
        User::factory()->create(['is_guest' => true, 'country' => null, 'phone' => null, 'gender' => null]);

        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('Customer accounts (guest shadows excluded): 1')
            ->expectsOutputToContain('Missing at least one detail: 0')
            ->assertExitCode(0);
    }

    public function test_it_flags_a_country_value_that_is_not_an_iso_code(): void
    {
        User::factory()->create(['country' => 'Nigeria', 'phone' => '+2348031234567', 'gender' => 'female']);

        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('not a 2-letter ISO code')
            ->assertExitCode(0);
    }

    public function test_it_separates_buyers_from_accounts_that_never_ordered(): void
    {
        $buyer = User::factory()->create(['country' => null, 'phone' => null, 'gender' => null]);
        Order::factory()->create(['user_id' => $buyer->id]);

        User::factory()->create(['country' => null, 'phone' => null, 'gender' => null]);

        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('Has ordered')
            ->expectsOutputToContain('Never ordered')
            ->assertExitCode(0);
    }

    public function test_it_survives_an_empty_customer_table(): void
    {
        $this->artisan('users:profile-completeness')
            ->expectsOutputToContain('No customer accounts found.')
            ->assertExitCode(0);
    }
}
