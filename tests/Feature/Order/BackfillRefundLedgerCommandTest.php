<?php

namespace Tests\Feature\Order;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Queries\FinanceReportQuery;
use App\Domain\Audit\Models\AuditLog;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * finance:backfill-refunds books every refund issued before the ledger
 * existed, dated and attributed from the audit row each admin refund wrote.
 */
class BackfillRefundLedgerCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A $40 order sold in June, with refund records already in its metadata
     * the way every refund has always written them.
     *
     * @param  array<string, float>  $records
     */
    private function refundedOrder(array $records, string $soldAt = '2026-06-15 12:00:00'): Order
    {
        $category = Category::firstOrCreate(['name' => 'eSIMs'], ['slug' => 'esims', 'type' => 'digital', 'is_active' => true, 'sort_order' => 0]);
        $product = Product::factory()->create(['category_id' => $category->id, 'provider_name' => 'airalo']);

        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'RSR-BKF-'.Str::random(8),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 40,
            'markup_amount' => 0,
            'total_amount' => 40,
            'payment_method' => 'flutterwave',
            'payment_status' => 'partially_refunded',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'completed_at' => Carbon::parse($soldAt),
            'metadata' => [
                'exchange_rate' => 1,
                'refund_records' => $records,
                'refunded_total' => round(array_sum($records), 2),
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => ProductVariant::factory()->create(['product_id' => $product->id])->id,
            'category_id' => $category->id,
            'subcategory_id' => Subcategory::factory()->create(['category_id' => $category->id])->id,
            'provider_name' => 'airalo',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 40,
            'provider_cost_usd' => 30,
            'markup_amount' => 0,
            'subtotal_amount' => 40,
            'fulfillment_status' => 'fulfilled',
        ]);

        return $order->fresh();
    }

    /**
     * The audit row AdminCommerceController writes beside every admin refund.
     */
    private function auditRefund(Order $order, float $amount, string $at, string $reason = 'Customer asked', ?int $adminId = null): AuditLog
    {
        $this->travelTo(Carbon::parse($at));

        $audit = AuditLog::create([
            'action' => 'admin.order_refunded',
            'model_type' => Order::class,
            'model_id' => $order->id,
            'metadata' => [
                'actor_type' => Admin::class,
                'actor_id' => $adminId,
                'amount' => $amount,
                'gateway' => 'flutterwave',
                'reason' => $reason,
            ],
        ]);

        $this->travelBack();

        return $audit;
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Backfill Admin',
            'email' => 'backfill-'.Str::random(6).'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_an_admin_refund_takes_its_date_reason_and_admin_from_its_audit_row(): void
    {
        $admin = $this->admin();
        $tag = 'admin-'.Str::uuid();
        $order = $this->refundedOrder([$tag => 10.00]);
        $audit = $this->auditRefund($order, 10.00, '2026-07-03 09:30:00', 'eSIM never installed', $admin->id);

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $row = OrderRefund::where('tag', $tag)->firstOrFail();
        $this->assertSame(OrderRefund::KIND_CUSTOMER_REFUND, $row->kind);
        $this->assertSame('2026-07-03 09:30:00', $row->refunded_at->format('Y-m-d H:i:s'));
        $this->assertSame('eSIM never installed', $row->reason);
        $this->assertSame('flutterwave', $row->gateway);
        $this->assertSame($admin->id, $row->refunded_by);
        $this->assertEqualsWithDelta(10.00, (float) $row->revenue_reversed_usd, 0.001);
        $this->assertTrue($row->metadata['backfilled']);
        $this->assertSame($audit->id, $row->metadata['audit_log_id']);
    }

    /**
     * Two refunds of the same amount pair with their audit rows in order, so
     * each lands in the month it was really issued.
     */
    public function test_repeated_refunds_pair_with_their_audit_rows_in_order(): void
    {
        $first = 'admin-'.Str::uuid();
        $second = 'admin-'.Str::uuid();
        $order = $this->refundedOrder([$first => 5.00, $second => 5.00]);
        $this->auditRefund($order, 5.00, '2026-07-01 10:00:00', 'First', null);
        $this->auditRefund($order, 5.00, '2026-08-01 10:00:00', 'Second', null);

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $this->assertSame('2026-07', OrderRefund::where('tag', $first)->firstOrFail()->refunded_at->format('Y-m'));
        $this->assertSame('First', OrderRefund::where('tag', $first)->value('reason'));
        $this->assertSame('2026-08', OrderRefund::where('tag', $second)->firstOrFail()->refunded_at->format('Y-m'));
        $this->assertSame('Second', OrderRefund::where('tag', $second)->value('reason'));
    }

    /**
     * An admin removed since keeps the refund, dated and explained, without a
     * dangling key; the audit trail still names them.
     */
    public function test_a_refund_by_a_removed_admin_is_still_booked(): void
    {
        $tag = 'admin-'.Str::uuid();
        $order = $this->refundedOrder([$tag => 10.00]);
        $this->auditRefund($order, 10.00, '2026-07-03 09:30:00', 'Duplicate order', 999);

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $row = OrderRefund::where('tag', $tag)->firstOrFail();
        $this->assertNull($row->refunded_by);
        $this->assertSame('Duplicate order', $row->reason);
    }

    public function test_an_admin_refund_with_no_audit_row_is_booked_at_the_last_update_and_marked_estimated(): void
    {
        $tag = 'admin-'.Str::uuid();
        $order = $this->refundedOrder([$tag => 10.00]);
        Order::whereKey($order->id)->update(['updated_at' => '2026-08-20 14:00:00']);

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $row = OrderRefund::where('tag', $tag)->firstOrFail();
        $this->assertSame('2026-08-20 14:00:00', $row->refunded_at->format('Y-m-d H:i:s'));
        $this->assertTrue($row->metadata['refunded_at_estimated']);
        $this->assertNull($row->reason);
    }

    public function test_a_failed_delivery_reversal_is_booked_as_a_reversal_that_reverses_no_revenue(): void
    {
        $order = $this->refundedOrder(['settle-'.Str::uuid() => 40.00]);

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $row = OrderRefund::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(OrderRefund::KIND_FULFILMENT_REVERSAL, $row->kind);
        $this->assertEqualsWithDelta(0.0, (float) $row->revenue_reversed_usd, 0.001);
        $this->assertSame('2026-06-15', $row->refunded_at->format('Y-m-d'));
    }

    public function test_running_it_twice_books_each_refund_once(): void
    {
        $order = $this->refundedOrder(['admin-'.Str::uuid() => 10.00, 'admin-'.Str::uuid() => 4.00]);
        $this->auditRefund($order, 10.00, '2026-07-01 10:00:00');
        $this->auditRefund($order, 4.00, '2026-07-02 10:00:00');

        $this->artisan('finance:backfill-refunds')->assertSuccessful();
        $this->artisan('finance:backfill-refunds')
            ->expectsOutputToContain('0 refunds booked; 2 already in the ledger')
            ->assertSuccessful();

        $this->assertSame(2, OrderRefund::count());
    }

    public function test_a_dry_run_writes_nothing_and_reports_what_it_would_book(): void
    {
        $order = $this->refundedOrder(['admin-'.Str::uuid() => 10.00, 'settle-'.Str::uuid() => 2.00]);
        $this->auditRefund($order, 10.00, '2026-07-03 09:30:00');

        $this->artisan('finance:backfill-refunds', ['--dry-run' => true])
            ->expectsTable(
                ['Month', 'Customer refunds', 'Revenue coming off (USD)', 'Failed-delivery reversals', 'Date estimated'],
                [['2026-06', 0, '0.00', 1, 0], ['2026-07', 1, '10.00', 0, 0]],
            )
            ->expectsOutputToContain('DRY RUN: 2 refunds would be booked')
            ->assertSuccessful();

        $this->assertSame(0, OrderRefund::count());
    }

    public function test_orders_with_an_untagged_refunded_total_are_flagged(): void
    {
        $order = $this->refundedOrder(['admin-'.Str::uuid() => 10.00]);
        $order->update(['metadata' => array_merge($order->metadata, ['refunded_total' => 15.00])]);

        $this->artisan('finance:backfill-refunds', ['--dry-run' => true])
            ->expectsOutputToContain('1 orders show a refunded total larger than their refund records')
            ->assertSuccessful();
    }

    /**
     * The point of it all: after the backfill, a June sale refunded in July
     * still shows in June, and the refund comes off July.
     */
    public function test_after_the_backfill_the_finances_page_deducts_the_refund_in_its_month(): void
    {
        $order = $this->refundedOrder(['admin-'.Str::uuid() => 10.00]);
        $this->auditRefund($order, 10.00, '2026-07-03 09:30:00');

        $this->artisan('finance:backfill-refunds')->assertSuccessful();

        $finance = app(FinanceReportQuery::class);
        $june = $finance->monthlyBreakdown(Carbon::parse('2026-06-01'));
        $july = $finance->monthlyBreakdown(Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(40.00, $june['totals']['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(-10.00, $july['totals']['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(10.00, $july['refunds']['total_usd'], 0.001);
    }
}
