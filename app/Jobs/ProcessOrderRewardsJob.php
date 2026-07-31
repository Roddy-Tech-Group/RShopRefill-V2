<?php

namespace App\Jobs;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Rewards\Services\RewardEngine;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderRewardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly Order $order
    ) {
        // Explicit queue: keeps one workload from starving unrelated ones (bulkhead).
        $this->onQueue('rewards');
    }

    /**
     * Execute the job.
     *
     * Fresh-loads the order so a delayed fraud-hold dispatch sees the latest
     * status - if the order was refunded / cancelled during the hold window
     * we skip the credit entirely. The serialised order on `$this->order`
     * would otherwise be stale by the time the queue worker fires.
     */
    public function handle(RewardEngine $rewardEngine): void
    {
        $fresh = Order::find($this->order->id);

        if ($fresh && $fresh->order_status === OrderStatus::Completed) {
            $rewardEngine->processOrderRewards($fresh);
        }
    }
}
