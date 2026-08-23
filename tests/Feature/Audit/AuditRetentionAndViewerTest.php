<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The final audit-hardening slice: password-change auditing (never the hash),
 * the viewer's customer-target search and date-range filters, and the
 * archive-then-delete retention command.
 */
class AuditRetentionAndViewerTest extends TestCase
{
    use RefreshDatabase;

    /** Write an audit row and backdate it via the query builder (the model is append-only). */
    private function backdatedLog(string $action, int $daysAgo): AuditLog
    {
        $log = AuditLog::create(['action' => $action]);
        DB::table('audit_logs')->where('id', $log->id)->update([
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);

        return $log->fresh();
    }

    // --- Password-change auditing --------------------------------------------

    public function test_a_password_change_is_audited_without_the_hash(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => Hash::make('brand-new-secret')]);

        $log = AuditLog::where('action', 'user.password_changed')->where('model_id', $user->id)->first();
        $this->assertNotNull($log);
        $this->assertNull($log->before_state);
        $this->assertNull($log->after_state);
        $this->assertStringNotContainsString('brand-new-secret', json_encode($log->getAttributes()));
    }

    public function test_a_non_password_update_does_not_claim_a_password_change(): void
    {
        $user = User::factory()->create();

        $user->update(['name' => 'New Name']);

        $this->assertSame(0, AuditLog::where('action', 'user.password_changed')->count());
    }

    // --- Viewer: customer-target search and date range ------------------------

    public function test_search_finds_events_targeting_a_customer_by_email(): void
    {
        $customer = User::factory()->create(['email' => 'needle@example.test']);
        AuditLog::create(['action' => 'user.sensitive_updated', 'model_type' => User::class, 'model_id' => $customer->id]);
        AuditLog::create(['action' => 'unrelated_event']);

        Volt::test('admin.account-activity')
            ->set('search', 'needle@example.test')
            ->assertSee('user.sensitive_updated')
            ->assertDontSee('unrelated_event');
    }

    public function test_search_finds_events_a_customer_performed(): void
    {
        $customer = User::factory()->create(['email' => 'actor@example.test']);
        AuditLog::create(['action' => 'customer_did_this', 'actor_id' => $customer->id]);
        AuditLog::create(['action' => 'someone_else_did_this']);

        Volt::test('admin.account-activity')
            ->set('search', 'actor@example.test')
            ->assertSee('customer_did_this')
            ->assertDontSee('someone_else_did_this');
    }

    public function test_the_date_range_filter_bounds_the_list(): void
    {
        $this->backdatedLog('ancient_event', 40);
        AuditLog::create(['action' => 'recent_event']);

        Volt::test('admin.account-activity')
            ->set('dateFrom', now()->subDays(7)->toDateString())
            ->assertSee('recent_event')
            ->assertDontSee('ancient_event');

        Volt::test('admin.account-activity')
            ->set('dateTo', now()->subDays(30)->toDateString())
            ->assertSee('ancient_event')
            ->assertDontSee('recent_event');
    }

    public function test_clear_filters_resets_every_filter_and_restores_the_full_list(): void
    {
        $this->backdatedLog('ancient_event', 40);
        AuditLog::create(['action' => 'recent_event']);

        Volt::test('admin.account-activity')
            ->set('search', 'nothing-matches-this')
            ->set('dateFrom', now()->subDays(7)->toDateString())
            ->set('actorFilter', 'admin')
            ->set('actionFilter', 'financial')
            ->assertDontSee('recent_event')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('dateFrom', '')
            ->assertSet('dateTo', '')
            ->assertSet('actorFilter', 'all')
            ->assertSet('actionFilter', 'all')
            ->assertSee('recent_event')
            ->assertSee('ancient_event');
    }

    // --- Retention: audit:prune ----------------------------------------------

    public function test_prune_archives_then_deletes_only_rows_past_the_window(): void
    {
        Storage::fake('local');
        $old = $this->backdatedLog('ancient_event', 800);
        $recent = AuditLog::create(['action' => 'recent_event']);

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);

        // The archive holds the pruned row, and the prune audited itself.
        $files = Storage::disk('local')->files('audit-archive');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('ancient_event', Storage::disk('local')->get($files[0]));
        $this->assertNotNull(AuditLog::where('action', 'audit.pruned')->first());
    }

    public function test_prune_dry_run_touches_nothing(): void
    {
        Storage::fake('local');
        $old = $this->backdatedLog('ancient_event', 800);

        $this->artisan('audit:prune --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['id' => $old->id]);
        $this->assertCount(0, Storage::disk('local')->files('audit-archive'));
    }

    public function test_prune_refuses_a_window_that_would_gut_the_trail(): void
    {
        $this->artisan('audit:prune --days=7')->assertFailed();
    }

    public function test_prune_honours_a_custom_window(): void
    {
        Storage::fake('local');
        $old = $this->backdatedLog('ancient_event', 100);

        $this->artisan('audit:prune --days=90')->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
    }
}
