<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Services\AuditLogService;
use App\Domain\Communication\Mail\Security\DataExportRequestedByStaffMail;
use App\Domain\Communication\Services\CommunicationManager;
use App\Models\AccountClosureFeedback;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The admin User Management group: moderation, closures (with the retention
 * health tiles), exit feedback, audience insights, and the staff-side data
 * export.
 */
class UserManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<int, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(CommunicationManager::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->andReturnUsing(function (...$args): void {
                $this->sent[] = $args;
            });
        });
    }

    private function admin(AdminRole $role = AdminRole::SuperAdmin): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => uniqid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_the_blocked_page_lists_every_kind_of_restriction(): void
    {
        $banned = User::factory()->create(['banned_at' => now()]);
        $suspended = User::factory()->create(['suspended_at' => now(), 'suspension_reason' => 'Chargeback dispute']);
        User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.blocked'))
            ->assertOk()
            ->assertSee($banned->name)
            ->assertSee($suspended->name)
            ->assertSee('Chargeback dispute')
            ->assertSee('Banned');
    }

    public function test_the_closures_page_separates_pending_from_closed(): void
    {
        $pending = User::factory()->create(['closure_requested_at' => now()->subDays(3)]);
        $closed = User::factory()->create(['name' => 'Closed account', 'anonymised_at' => now()->subDay()]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.closures'))
            ->assertOk()
            ->assertSee($pending->name)
            ->assertSee('Cancel closure')
            // Closed accounts are anonymised; the page shows counts, not rows.
            ->assertDontSee('Account #'.$closed->id)
            ->assertSee('Accounts closed all-time')
            ->assertSee('Identities in compliance hold');
    }

    public function test_the_feedback_page_aggregates_without_identifying_anyone(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        AccountClosureFeedback::create(['user_id' => $user->id, 'reasons' => ['prices_fees'], 'comment' => 'Fees felt high on airtime.']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.feedback'))
            ->assertOk()
            ->assertSee('Prices or fees')
            ->assertSee('Fees felt high on airtime.')
            ->assertDontSee('ada@example.test');
    }

    public function test_the_insights_page_counts_countries_and_exports_csv(): void
    {
        User::factory()->count(3)->create(['country' => 'NG']);
        User::factory()->create(['country' => 'CM']);
        User::factory()->create(['country' => null]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.insights'))
            ->assertOk()
            ->assertSee('Nigeria')
            ->assertSee('Unknown');

        $csv = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.users.insights.export'));

        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Nigeria', $csv->streamedContent());
    }

    public function test_a_moderator_cannot_open_the_group(): void
    {
        // Moderators have no `customers` section, and users.* shares that
        // grant. Denied admin routes 404 by design (AdminAuth hides the page's
        // existence rather than confirming it with a 403).
        $this->actingAs($this->admin(AdminRole::Moderator), 'admin')
            ->get(route('admin.users.insights'))
            ->assertNotFound();
    }

    public function test_staff_can_email_a_customer_their_data_export(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.send-data-export', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        $mail = collect($this->sent)->first(fn (array $args) => ($args[4] ?? null) instanceof DataExportRequestedByStaffMail);
        $this->assertNotNull($mail);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.data_export_sent']);
    }

    public function test_staff_can_sign_a_customer_out_everywhere(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token-value']);
        DB::table('sessions')->insert([
            'id' => 'sess-'.uniqid(),
            'user_id' => $user->id,
            'payload' => base64_encode('a:0:{}'),
            'last_activity' => time(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.revoke-sessions', $user))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNotSame('old-token-value', $user->fresh()->remember_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.sessions_revoked']);
    }

    public function test_the_customer_page_shows_history_and_recent_emails(): void
    {
        $user = User::factory()->create();

        app(AuditLogService::class)->log(
            action: 'admin.wallet_adjusted',
            model: $user,
            metadata: ['reason' => 'Goodwill credit'],
        );
        DB::table('communication_logs')->insert([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'mail_domain' => 'security',
            'category' => 'login_alert',
            'channel' => 'email',
            'recipient' => $user->email,
            'sender' => 'security@rshoprefill.com',
            'subject' => 'New sign-in to your account',
            'status' => 'delivered',
            'provider' => 'resend',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.customer', $user))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Admin: wallet adjusted')
            ->assertSee('Goodwill credit')
            ->assertSee('New sign-in to your account')
            ->assertSee('Delivered');
    }

    public function test_a_closed_account_has_nothing_left_to_export(): void
    {
        $user = User::factory()->create(['anonymised_at' => now()]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.send-data-export', $user))
            ->assertSessionHasErrors('data_export');

        $this->assertSame([], $this->sent);
    }
}
