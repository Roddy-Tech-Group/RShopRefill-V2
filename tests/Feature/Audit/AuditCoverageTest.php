<?php

namespace Tests\Feature\Audit;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogService;
use App\Models\Admin;
use App\Models\RcoinWithdrawal;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The audit gaps closed in the industry-standard review: logout and lockout
 * events, admin-actor attribution across the queue boundary, admin-account
 * lifecycle changes, refunds, and bulk data exports.
 */
class AuditCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role = AdminRole::SuperAdmin): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_a_logout_is_audited(): void
    {
        $user = User::factory()->create();

        event(new Logout('web', $user));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_logout',
            'actor_id' => $user->id,
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_an_auth_lockout_is_audited(): void
    {
        $request = Request::create('/login', 'POST', ['email' => 'target@example.test']);

        event(new Lockout($request));

        $log = AuditLog::where('action', 'auth_lockout')->first();
        $this->assertNotNull($log);
        $this->assertSame('target@example.test', $log->metadata['email']);
    }

    public function test_an_admin_login_carries_admin_actor_attribution(): void
    {
        $admin = $this->admin();

        event(new Login('admin', $admin, false));

        $log = AuditLog::where('action', 'admin_login')->where('model_type', Admin::class)->first();
        $this->assertNotNull($log);
        // The listener is queued, so the attribution must come from the event
        // itself; without it admin logins would read as "System" in the viewer.
        $this->assertSame(Admin::class, $log->metadata['actor_type']);
        $this->assertSame($admin->email, $log->metadata['actor_email']);
        $this->assertNull($log->actor_id);
    }

    public function test_an_admin_is_attributed_even_when_the_default_guard_was_not_switched(): void
    {
        $admin = $this->admin();

        // Log in on the admin guard WITHOUT marking it as the default guard -
        // the state Livewire component actions can arrive in. This used to
        // record the action as "System".
        Auth::guard('admin')->login($admin);

        app(AuditLogService::class)->log('guard_fallback_probe');

        $log = AuditLog::where('action', 'guard_fallback_probe')->first();
        $this->assertNotNull($log);
        $this->assertSame(Admin::class, $log->metadata['actor_type']);
        $this->assertSame($admin->name, $log->metadata['actor_name']);
    }

    public function test_creating_an_admin_is_audited(): void
    {
        $actor = $this->admin();

        $this->actingAs($actor, 'admin');

        Volt::test('admin.admins')
            ->call('newAdmin')
            ->set('name', 'New Colleague')
            ->set('email', 'colleague@example.test')
            ->set('password', 'password-123')
            ->set('role', AdminRole::Admin->value)
            ->call('save');

        $log = AuditLog::where('action', 'admin.admin_created')->first();
        $this->assertNotNull($log);
        $this->assertSame('colleague@example.test', $log->after_state['email']);
        $this->assertSame(Admin::class, $log->metadata['actor_type']);
    }

    public function test_changing_an_admin_role_is_audited_with_before_and_after(): void
    {
        $actor = $this->admin();
        $target = $this->admin(AdminRole::Admin);

        $this->actingAs($actor, 'admin');

        Volt::test('admin.admins')
            ->call('edit', $target->id)
            ->set('role', AdminRole::SuperAdmin->value)
            ->call('save');

        $log = AuditLog::where('action', 'admin.admin_updated')->where('model_id', $target->id)->first();
        $this->assertNotNull($log);
        $this->assertSame(AdminRole::Admin->value, $log->before_state['role']->value ?? $log->before_state['role']);
        $this->assertSame(AdminRole::SuperAdmin->value, $log->after_state['role']);
        $this->assertFalse($log->metadata['password_changed']);
    }

    public function test_deactivating_an_admin_is_audited(): void
    {
        $actor = $this->admin();
        $target = $this->admin(AdminRole::Admin);

        $this->actingAs($actor, 'admin');

        Volt::test('admin.admins')->call('toggleActive', $target->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.admin_deactivated',
            'model_type' => Admin::class,
            'model_id' => $target->id,
        ]);
    }

    public function test_switching_into_another_admin_account_is_audited(): void
    {
        $actor = $this->admin();
        $target = $this->admin(AdminRole::Admin);

        $this->actingAs($actor, 'admin');

        Volt::test('admin.admins')->call('loginAs', $target->id);

        $log = AuditLog::where('action', 'admin.admin_switched')->where('model_id', $target->id)->first();
        $this->assertNotNull($log);
        // Attributed to the switching super admin, captured before the guard flip.
        $this->assertSame($actor->id, $log->metadata['actor_id']);
    }

    public function test_the_transactions_export_is_audited(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get(route('admin.transactions.export', ['status' => 'completed']))->assertOk();

        $log = AuditLog::where('action', 'report.transactions_exported')->first();
        $this->assertNotNull($log);
        $this->assertSame('completed', $log->metadata['status']);
        $this->assertSame(Admin::class, $log->metadata['actor_type']);
    }

    public function test_the_metrics_export_is_audited(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get(route('admin.reports.export'))->assertOk();

        $this->assertNotNull(AuditLog::where('action', 'report.metrics_exported')->first());
    }

    public function test_the_orders_export_is_audited(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get(route('admin.api.commerce.orders.export'))->assertOk();

        $this->assertNotNull(AuditLog::where('action', 'report.orders_exported')->first());
    }

    public function test_a_withdrawal_decision_is_audited_with_amount_and_reason(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $withdrawal = RcoinWithdrawal::create([
            'user_id' => $user->id,
            'rcoin_amount' => 500,
            'usd_value' => 5.0,
            'fee_usd' => 0.5,
            'payout_usd' => 4.5,
            'method' => 'bank',
            'payout_details' => ['bank' => 'Test Bank'],
            'rate_snapshot' => 0.01,
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin');

        Volt::test('admin.content.rewards-withdrawals')
            ->set('rejectingId', $withdrawal->id)
            ->set('rejectReason', 'Payout details did not match')
            ->call('reject', $withdrawal->id);

        $log = AuditLog::where('action', 'admin.withdrawal_rejected')->first();
        $this->assertNotNull($log, 'rejecting a withdrawal must leave an audit record');
        $this->assertSame(500, (int) $log->metadata['rcoin_amount']);
        $this->assertSame('Payout details did not match', $log->metadata['reason']);
        $this->assertSame(Admin::class, $log->metadata['actor_type']);
    }

    public function test_resetting_a_customer_transaction_pin_is_audited(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => bcrypt('9482'),
            'transaction_pin_set_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.reset-pin', $user))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction_pin.admin_reset',
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
    }

    public function test_both_ends_of_an_impersonation_are_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.customer.login-as', $user))
            ->assertRedirect();

        $this->post(route('impersonation.leave'))->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.impersonation_started', 'model_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.impersonation_ended', 'model_id' => $user->id]);
    }
}
