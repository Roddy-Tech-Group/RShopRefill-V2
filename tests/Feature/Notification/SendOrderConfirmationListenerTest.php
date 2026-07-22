<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Enums\CommunicationCategory;
use App\Domain\Communication\Jobs\DispatchCommunicationJob;
use App\Domain\Order\Events\OrderPlaced;
use App\Domain\Order\Events\RefundIssued;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The commerce listeners must build their messages and mailables from real
 * order data without crashing (regression: the old listener read a
 * non-existent Order::$currency enum and rolled back payment confirmation).
 */
class SendOrderConfirmationListenerTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(User $user, float $total, string $currency = 'NGN'): Order
    {
        return Order::factory()->create([
            'user_id' => $user->id,
            'display_currency' => $currency,
            'settlement_currency' => $currency,
            'total_amount' => $total,
        ]);
    }

    public function test_order_placed_builds_confirmation_without_crashing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $order = $this->orderFor($user, 6000.00, 'USD');

        event(new OrderPlaced($order));

        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $job) use ($order) {
            return $job->payload->category === CommunicationCategory::OrderPlaced
                && str_contains($job->payload->message, $order->order_number);
        });
    }

    public function test_refund_message_builds_without_null_currency_crash(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $order = $this->orderFor($user, 2800.00, 'GHS');

        event(new RefundIssued($order, 100.0, 'duplicate charge'));

        Queue::assertPushed(DispatchCommunicationJob::class, function (DispatchCommunicationJob $job) use ($order) {
            return $job->payload->category === CommunicationCategory::Refund
                && str_contains($job->payload->message, '100')
                && str_contains($job->payload->message, $order->order_number);
        });
    }
}
