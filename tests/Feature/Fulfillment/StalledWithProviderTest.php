<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Admin\Services\MonitoringSnapshotService;
use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * An order the provider accepted and never resolved.
 *
 * This had no owner. The poll sweeper only looks at items touched in the last
 * thirty minutes, so past that an item fell out of the net and nothing retried
 * it again. The stuck-orders alert only counts orders whose fulfilment never
 * STARTED, so an item sitting in processing or delayed matched nothing there
 * either. And the poll's pending branch threw the provider's answer away, so
 * fulfillment_payload stayed null and there was nothing to diagnose from.
 *
 * A real MTN Cameroon data top-up sat like that: paid, authorized by the
 * provider, held in IN_PROGRESS for over an hour, unswept, unalerted and
 * invisible. It surfaced only because the person who bought it owns the shop.
 */
class StalledWithProviderTest extends TestCase
{
    use RefreshDatabase;

    private function paidItem(FulfillmentStatus $status, int $minutesSinceTouched): OrderItem
    {
        $order = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'payment_status' => 'paid',
            'order_status' => 'processing',
            // Past not_started on purpose. The order-level stuck count catches
            // not_started orders, so leaving it there would make these two
            // counts overlap and this file would stop isolating the new one.
            'fulfillment_status' => 'processing',
            'placed_at' => now()->subMinutes($minutesSinceTouched + 5),
        ]);

        $item = OrderItem::factory()->for($order)->create([
            'fulfillment_status' => $status,
        ]);

        // Written straight to the column: touching the model would refresh the
        // timestamp this whole test is about.
        OrderItem::withoutTimestamps(fn () => $item->forceFill([
            'updated_at' => now()->subMinutes($minutesSinceTouched),
        ])->saveQuietly());

        return $item->refresh();
    }

    private function backlog(): array
    {
        return app(MonitoringSnapshotService::class)->snapshot()['orders'];
    }

    /** The regression: an item held by the provider is now counted. */
    public function test_an_item_the_provider_never_resolved_is_counted(): void
    {
        $this->paidItem(FulfillmentStatus::Delayed, 60);

        $this->assertSame(1, $this->backlog()['stalled_with_provider']);
    }

    /** Processing counts the same as delayed: both mean handed over, unresolved. */
    public function test_processing_counts_too(): void
    {
        $this->paidItem(FulfillmentStatus::Processing, 60);

        $this->assertSame(1, $this->backlog()['stalled_with_provider']);
    }

    /**
     * A top-up that resolves in two minutes is normal. The count must not fire
     * on ordinary in-flight work, or it becomes noise and gets ignored.
     */
    public function test_a_recently_touched_item_is_not_counted(): void
    {
        $this->paidItem(FulfillmentStatus::Processing, 5);

        $this->assertSame(0, $this->backlog()['stalled_with_provider']);
    }

    /** Delivered and failed items are resolved, whatever their age. */
    public function test_resolved_items_are_not_counted(): void
    {
        $this->paidItem(FulfillmentStatus::Fulfilled, 300);
        $this->paidItem(FulfillmentStatus::Failed, 300);

        $this->assertSame(0, $this->backlog()['stalled_with_provider']);
    }

    /** No payment, no money in limbo. */
    public function test_an_unpaid_order_is_not_counted(): void
    {
        $order = Order::factory()->create([
            'user_id' => User::factory()->create()->id,
            'payment_status' => 'failed',
            'order_status' => 'cancelled',
        ]);

        OrderItem::factory()->for($order)->create([
            'fulfillment_status' => FulfillmentStatus::Delayed,
        ]);

        $this->assertSame(0, $this->backlog()['stalled_with_provider']);
    }

    /**
     * The two counts answer different questions and must not be conflated.
     *
     * The old one is about an order whose fulfilment never began. The new one
     * is about an item the provider took and did not finish. An order can be
     * in both at once, which is what the real MTN case looked like, so the
     * order here is moved past not_started to isolate the new count.
     */
    public function test_the_never_started_count_is_separate(): void
    {
        $this->paidItem(FulfillmentStatus::Delayed, 60);

        $backlog = $this->backlog();

        $this->assertSame(0, $backlog['stuck'], 'an order past not_started is not "never started"');
        $this->assertSame(1, $backlog['stalled_with_provider']);
    }

    /** The cutoff stops being an exit: there is a lane for what the fast one drops. */
    public function test_a_second_sweeper_covers_what_the_first_one_drops(): void
    {
        $console = File::get(base_path('routes/console.php'));

        $this->assertStringContainsString("->name('fulfillment:poll-pending')", $console);
        $this->assertStringContainsString("->name('fulfillment:poll-stalled')", $console);

        // The fast lane takes the recent ones, the slow lane takes the rest.
        $this->assertStringContainsString("->where('updated_at', '>=', \$cutoff)", $console);
        $this->assertStringContainsString("->where('updated_at', '<', now()->subMinutes(30))", $console);

        // And what neither can resolve goes to a person, rather than nowhere.
        $this->assertStringContainsString('OrderStatus::RequiresAttention', $console);
    }

    /** The provider's last word is kept, so diagnosing needs no API call by hand. */
    public function test_the_poll_records_what_the_provider_said(): void
    {
        $job = File::get(app_path('Jobs/PollPendingFulfillmentJob.php'));

        $this->assertStringContainsString("'last_provider_status' => [", $job);

        // In metadata, not fulfillment_payload: that column is what the
        // customer receives, and a status blob there reads as a delivery.
        $this->assertMatchesRegularExpression(
            '/\$item->metadata = array_merge\(\$item->metadata \?\? \[\], \[\s*\'last_provider_status\'/',
            $job
        );
    }

    /** Monitoring says something about it, which is the whole point. */
    public function test_there_is_an_alert_for_it(): void
    {
        $command = File::get(app_path('Console/Commands/MonitorOperationsCommand.php'));

        $this->assertStringContainsString("key: 'orders:stalled-with-provider'", $command);
        $this->assertStringContainsString('$this->checkStalledWithProvider($snapshot[\'orders\']);', $command);
    }
}
