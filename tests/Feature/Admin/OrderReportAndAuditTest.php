<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Services\OrderReportService;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The order evidence report and the audit-trail hardening: report generation is
 * audited, sensitive User changes are captured at the model layer regardless of
 * surface, settings flips are attributable, the trail is append-only, and the
 * admin viewer renders and filters.
 */
class OrderReportAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_the_report_opens_as_a_preview_and_the_view_is_audited(): void
    {
        $order = Order::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.order.report', $order))
            ->assertOk()
            ->assertSee('Order Report: '.$order->order_number)
            ->assertSee('Download')
            ->assertSee('Share');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order_report.viewed',
            'model_type' => Order::class,
        ]);
    }

    public function test_downloading_the_pdf_audits_the_generation(): void
    {
        $order = Order::factory()->create();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.order.report.pdf', [$order, 'download' => 1]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('order-report-'.$order->order_number, (string) $response->headers->get('content-disposition'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'order_report.generated',
            'model_type' => Order::class,
        ]);
    }

    public function test_the_inline_pdf_streams_without_a_generation_audit(): void
    {
        $order = Order::factory()->create();

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.order.report.pdf', $order));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'order_report.generated']);
    }

    public function test_the_report_is_admin_only(): void
    {
        $order = Order::factory()->create();

        $this->get(route('admin.order.report', $order))->assertNotFound();
    }

    public function test_a_display_currency_item_is_reported_in_usd_with_the_customer_amount_beneath(): void
    {
        // RSR-20260927-ZVTQUW: XAF 4,562.94 of airtime at 575.604916 is $7.93,
        // and the report printed it as $4,562.94.
        $order = $this->orderWithItem('XAF', 4562.94, ['exchange_rate' => 575.604916]);

        $data = app(OrderReportService::class)->assemble($order, 'admin@example.test');
        $item = $data['items'][0];

        $this->assertEqualsWithDelta(7.9272, $item['subtotal_usd'], 0.0001);
        $this->assertSame('XAF', $item['display_currency']);
        $this->assertEqualsWithDelta(4562.94, $item['subtotal_display'], 0.001);

        $html = view('admin.reports.order-report-pdf', ['data' => $data])->render();

        $this->assertTrue(str_contains($html, '$7.93'), 'the item subtotal should read $7.93');
        $this->assertTrue(str_contains($html, 'XAF 4,562.94'), 'the customer amount should read XAF 4,562.94');
        $this->assertFalse(str_contains($html, '$4,562.94'), 'the XAF amount must not be printed as dollars');
    }

    public function test_a_usd_item_is_reported_as_is_without_a_customer_line(): void
    {
        $order = $this->orderWithItem('USD', 10.00, ['exchange_rate' => 1]);

        $item = app(OrderReportService::class)->assemble($order, 'admin@example.test')['items'][0];

        $this->assertEqualsWithDelta(10.00, $item['subtotal_usd'], 0.0001);
        $this->assertNull($item['display_currency']);
    }

    public function test_an_item_on_an_order_without_a_rate_keeps_its_stored_amount_and_hides_the_customer_line(): void
    {
        // Pre-snapshot orders stored the USD figure under a non-USD label, so
        // the stored amount is the only honest one and the label is not shown.
        $order = $this->orderWithItem('XAF', 12.50, []);

        $item = app(OrderReportService::class)->assemble($order, 'admin@example.test')['items'][0];

        $this->assertEqualsWithDelta(12.50, $item['subtotal_usd'], 0.0001);
        $this->assertNull($item['display_currency']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function orderWithItem(string $currency, float $subtotal, array $metadata): Order
    {
        $order = Order::factory()->create([
            'display_currency' => $currency,
            'settlement_currency' => $currency,
            'total_amount' => $subtotal,
            'metadata' => $metadata,
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'display_currency' => $currency,
            'display_amount' => $subtotal,
            'subtotal_amount' => $subtotal,
        ]);

        return $order;
    }

    public function test_sensitive_user_changes_are_audited_from_any_surface(): void
    {
        $user = User::factory()->create(['kyc_status' => 'unsubmitted']);

        // No controller involved: a bare model update (webhook/console shape).
        $user->update(['kyc_status' => 'verified']);

        $log = AuditLog::query()->where('action', 'user.sensitive_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('unsubmitted', $log->before_state['kyc_status']);
        $this->assertSame('verified', $log->after_state['kyc_status']);
    }

    public function test_non_sensitive_user_changes_are_not_audited(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => bcrypt('new-password-123')]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.sensitive_updated']);
    }

    public function test_banning_via_the_admin_panel_is_audited_with_the_admin_actor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.ban', $user))
            ->assertRedirect();

        $log = AuditLog::query()->where('action', 'user.sensitive_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->after_state['banned_at']);
        $this->assertSame('admin@example.test', $log->metadata['actor_email'] ?? null);
    }

    public function test_impersonation_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.customer.login-as', $user))
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.impersonation_started',
            'model_type' => User::class,
            'model_id' => (string) $user->id,
        ]);
    }

    public function test_the_audit_trail_is_append_only(): void
    {
        $log = AuditLog::create(['action' => 'test.entry']);

        $log->update(['action' => 'tampered']);

        $this->assertSame('test.entry', $log->fresh()->action);
    }

    public function test_account_activity_surfaces_the_new_audit_actions(): void
    {
        // Account Activity is THE audit viewer (one surface, not two): the new
        // coverage (settings flips, sensitive changes, report events) must be
        // findable there through its search.
        AuditLog::create(['action' => 'setting.updated', 'correlation_id' => 'find-me-here']);

        Volt::test('admin.account-activity')
            ->set('search', 'setting.updated')
            ->assertSee('setting.updated');
    }
}
