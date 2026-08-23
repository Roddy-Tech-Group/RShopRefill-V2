<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Order\Services\OrderReportService;
use App\Models\Admin;
use App\Models\CommunicationLog;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The transactions surface upgrade: Completed as the landing view, clickable
 * rows into a detail page, the transaction report (preview-first + audited),
 * and the order report's summarised communications/audit sections.
 */
class TransactionPagesTest extends TestCase
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

    private function attempt(string $status, string $customerName): PaymentAttempt
    {
        $user = User::factory()->create(['name' => $customerName]);
        $order = Order::factory()->create(['user_id' => $user->id]);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'flutterwave',
            'gateway_reference' => 'FLW-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => $status,
        ]);
    }

    // --- List: default view + clickable rows ---------------------------------

    public function test_the_transactions_page_defaults_to_completed(): void
    {
        $this->attempt('paid', 'Paid Customer');
        $this->attempt('failed', 'Failed Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('Paid Customer')
            ->assertDontSee('Failed Customer');
    }

    public function test_the_all_filter_is_still_reachable(): void
    {
        $this->attempt('paid', 'Paid Customer');
        $this->attempt('failed', 'Failed Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions', ['status' => 'all']))
            ->assertOk()
            ->assertSee('Paid Customer')
            ->assertSee('Failed Customer');
    }

    public function test_rows_link_to_the_transaction_detail_page(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        // The row's click target is emitted through @js(), which escapes the
        // URL's slashes; the attempt id inside it survives verbatim.
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transactions'))
            ->assertOk()
            ->assertSee('Livewire.navigate', false)
            ->assertSee($attempt->id, false);
    }

    // --- Detail page ----------------------------------------------------------

    public function test_the_detail_page_shows_the_attempt(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transaction', $attempt))
            ->assertOk()
            ->assertSee('Transaction #'.$attempt->id)
            ->assertSee('Paid Customer')
            ->assertSee($attempt->gateway_reference)
            ->assertSee('Generate Report')
            ->assertSee($attempt->order->order_number);
    }

    public function test_the_detail_page_lists_sibling_attempts(): void
    {
        $attempt = $this->attempt('failed', 'Retry Customer');
        $sibling = PaymentAttempt::create([
            'order_id' => $attempt->order_id,
            'user_id' => $attempt->user_id,
            'gateway' => 'korapay',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'USD',
            'amount' => 25.00,
            'payment_status' => 'paid',
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transaction', $attempt))
            ->assertOk()
            ->assertSee('Other attempts on this order')
            ->assertSee(route('admin.transaction', $sibling), false);
    }

    public function test_the_detail_page_is_admin_only(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        $this->get(route('admin.transaction', $attempt))->assertNotFound();
    }

    // --- Transaction report ---------------------------------------------------

    public function test_the_report_opens_as_a_preview_and_the_view_is_audited(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transaction.report', $attempt))
            ->assertOk()
            ->assertSee('Transaction Report: #'.$attempt->id)
            ->assertSee('Download')
            ->assertSee('Share');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction_report.viewed',
            'model_type' => PaymentAttempt::class,
        ]);
    }

    public function test_downloading_the_pdf_audits_the_generation(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transaction.report.pdf', [$attempt, 'download' => 1]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transaction_report.generated',
            'model_type' => PaymentAttempt::class,
        ]);
    }

    public function test_the_inline_pdf_streams_without_a_generation_audit(): void
    {
        $attempt = $this->attempt('paid', 'Paid Customer');

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.transaction.report.pdf', $attempt))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertSame(0, AuditLog::where('action', 'transaction_report.generated')->count());
    }

    // --- Order report summarisation -------------------------------------------

    public function test_the_order_report_shows_only_pre_order_logins(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        // Five logins BEFORE the order (the model is append-only, so backdate
        // via the query builder), plus one after, plus non-login noise.
        foreach (range(1, 5) as $i) {
            $log = AuditLog::create([
                'action' => 'user_login',
                'actor_id' => $user->id,
                'model_type' => User::class,
                'model_id' => $user->id,
                'ip_address' => '203.0.113.7',
            ]);
            DB::table('audit_logs')->where('id', $log->id)
                ->update(['created_at' => now()->subHours($i)]);
        }
        AuditLog::create([
            'action' => 'user_login',
            'model_type' => User::class,
            'model_id' => $user->id,
            'ip_address' => '198.51.100.9',
        ]);
        AuditLog::create([
            'action' => 'user.sensitive_updated',
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        // The order placed one minute after the pre-order logins' newest row.
        DB::table('orders')->where('id', $order->id)
            ->update(['placed_at' => now()->subHours(1)->addMinute()]);

        $data = app(OrderReportService::class)->assemble($order->fresh(), 'test@example.test');

        // Capped at the last 3 sign-ins before placement; the post-order login
        // (198.51.100.9) and non-login events never appear.
        $this->assertCount(3, $data['audit']);
        $this->assertSame('203.0.113.7', $data['audit'][0]['ip']);
        $this->assertArrayHasKey('at', $data['audit'][0]);
    }

    public function test_the_order_report_collapses_duplicate_communications(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        foreach (range(1, 3) as $_) {
            CommunicationLog::create([
                'uuid' => (string) Str::uuid(),
                'mail_domain' => 'orders',
                'sender' => 'orders@rshoprefill.com',
                'provider' => 'resend',
                'user_id' => $user->id,
                'channel' => 'email',
                'category' => 'order_placed',
                'recipient' => $user->email,
                'status' => 'sent',
                'correlation_id' => 'order_confirmed_'.$order->id,
            ]);
        }

        $data = app(OrderReportService::class)->assemble($order->fresh(), 'test@example.test');

        $this->assertCount(1, $data['communications']);
        $this->assertSame(3, $data['communications'][0]['count']);
    }
}
