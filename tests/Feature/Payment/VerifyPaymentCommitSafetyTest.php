<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\Events\PaymentConfirmed;
use App\Domain\Order\Services\OrderService;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Services\PaymentGatewayFactory;
use App\Jobs\VerifyPaymentJob;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VerifyPaymentJob used to dispatch PaymentConfirmed and the fulfillment jobs
 * from INSIDE its transaction while every queue connection runs
 * after_commit=false, so a rollback after the dispatch left workers fulfilling
 * an order whose Paid transition had just evaporated. The confirmation event
 * and the fulfillment dispatches now ride one DB::afterCommit closure: they
 * fire when the transaction commits and die with it when it rolls back.
 */
class VerifyPaymentCommitSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stranded settlement: attempt Paid, order still Pending. The job
     * finishes it without a gateway round-trip, so no provider mock needed.
     *
     * @return array{0: Order, 1: PaymentAttempt}
     */
    private function strandedSettlement(): array
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'payment_method' => 'paystack',
            'payment_status' => PaymentStatus::Pending,
        ]);

        $attempt = PaymentAttempt::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'gateway' => 'paystack',
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'NGN',
            'amount' => 5000,
            'payment_status' => 'paid',
        ]);

        return [$order, $attempt];
    }

    private function runJob(PaymentAttempt $attempt): void
    {
        $factory = $this->createMock(PaymentGatewayFactory::class);
        $factory->expects($this->never())->method('getProvider');

        (new VerifyPaymentJob($attempt))->handle($factory, app(OrderService::class));
    }

    public function test_the_confirmation_fires_once_the_transaction_commits(): void
    {
        Event::fake([PaymentConfirmed::class]);

        [$order, $attempt] = $this->strandedSettlement();

        $this->runJob($attempt);

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Event::assertDispatched(PaymentConfirmed::class);
    }

    public function test_a_rollback_takes_the_confirmation_and_fulfillment_with_it(): void
    {
        Event::fake([PaymentConfirmed::class]);

        [$order, $attempt] = $this->strandedSettlement();

        // An outer transaction that rolls back stands in for any failure
        // after the job's own writes: the deferred dispatches must be
        // discarded along with the data they describe.
        DB::beginTransaction();
        $this->runJob($attempt);
        DB::rollBack();

        Event::assertNotDispatched(PaymentConfirmed::class);
        $this->assertSame(
            PaymentStatus::Pending,
            $order->fresh()->payment_status,
            'the rollback must leave the order exactly as it was',
        );
    }
}
