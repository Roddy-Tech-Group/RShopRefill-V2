<?php

namespace Tests\Feature\Compliance;

use App\Domain\Shared\Enums\Currency;
use App\Models\ClosedAccountIdentity;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The retention windows the privacy policy publishes are enforced from
 * config/retention.php by one daily command, so the published number and
 * the running number are the same number.
 */
class RetentionEnforceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'retention.closed_account_identity_years' => 5,
            'retention.closed_account_financial_years' => 7,
            'retention.communication_logs_days' => 730,
            'retention.contact_messages_days' => 730,
        ]);
    }

    private function identity(User $user, string $retainUntil): ClosedAccountIdentity
    {
        return ClosedAccountIdentity::create([
            'user_id' => $user->id,
            'name' => 'Ada Okafor',
            'email' => 'ada@example.test',
            'closed_at' => now()->subYears(5),
            'retain_until' => $retainUntil,
        ]);
    }

    public function test_identities_are_removed_only_once_their_retention_date_has_passed(): void
    {
        $expired = User::factory()->create(['anonymised_at' => now()->subYears(5)]);
        $kept = User::factory()->create(['anonymised_at' => now()->subYears(2)]);
        $this->identity($expired, now()->subDay()->toDateTimeString());
        $this->identity($kept, now()->addYears(3)->toDateTimeString());

        $this->artisan('retention:enforce')->assertExitCode(0);

        $this->assertDatabaseMissing('closed_account_identities', ['user_id' => $expired->id]);
        $this->assertDatabaseHas('closed_account_identities', ['user_id' => $kept->id]);
    }

    public function test_financial_records_of_a_closed_account_go_after_the_published_window(): void
    {
        $longClosed = User::factory()->create(['anonymised_at' => now()->subYears(8)]);
        $wallet = Wallet::create(['user_id' => $longClosed->id, 'currency' => Currency::USD, 'balance' => 0]);
        $order = Order::factory()->create(['user_id' => $longClosed->id, 'order_status' => 'completed']);

        $recentlyClosed = User::factory()->create(['anonymised_at' => now()->subYears(2)]);
        $recentOrder = Order::factory()->create(['user_id' => $recentlyClosed->id, 'order_status' => 'completed']);

        $active = User::factory()->create();
        $activeOrder = Order::factory()->create(['user_id' => $active->id, 'order_status' => 'completed']);

        $this->artisan('retention:enforce')->assertExitCode(0);

        // Eight years closed: the order and wallet are gone, the tombstone row stays.
        $this->assertNull(Order::find($order->id));
        $this->assertNull(Wallet::find($wallet->id));
        $this->assertNotNull(User::find($longClosed->id));
        $this->assertNotNull($longClosed->fresh()->financial_records_purged_at);

        // Two years closed and an active customer: untouched.
        $this->assertNotNull(Order::find($recentOrder->id));
        $this->assertNotNull(Order::find($activeOrder->id));
        $this->assertNull($recentlyClosed->fresh()->financial_records_purged_at);
    }

    public function test_old_delivery_logs_and_contact_messages_are_pruned(): void
    {
        $user = User::factory()->create();

        foreach ([['old', now()->subDays(800)], ['new', now()->subDays(10)]] as [$tag, $at]) {
            DB::table('communication_logs')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'mail_domain' => 'security',
                'category' => 'login_alert',
                'channel' => 'email',
                'recipient' => "{$tag}@example.test",
                'sender' => 'security@rshoprefill.com',
                'provider' => 'resend',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            DB::table('contact_messages')->insert([
                'name' => $tag,
                'email' => "{$tag}@example.test",
                'message' => 'hello',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $this->artisan('retention:enforce')->assertExitCode(0);

        $this->assertDatabaseMissing('communication_logs', ['recipient' => 'old@example.test']);
        $this->assertDatabaseHas('communication_logs', ['recipient' => 'new@example.test']);
        $this->assertDatabaseMissing('contact_messages', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('contact_messages', ['email' => 'new@example.test']);
    }

    public function test_dry_run_reports_without_removing(): void
    {
        $expired = User::factory()->create(['anonymised_at' => now()->subYears(6)]);
        $this->identity($expired, now()->subDay()->toDateTimeString());

        $this->artisan('retention:enforce', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('closed_account_identities', ['user_id' => $expired->id]);
    }

    public function test_the_broken_legacy_purge_command_is_gone(): void
    {
        $this->assertArrayNotHasKey('compliance:purge', $this->app->make(Kernel::class)->all());
    }
}
