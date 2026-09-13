<?php

namespace App\Domain\Fulfillment\Console;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Services\FulfillmentProviderFactory;
use App\Domain\Order\Services\OrderService;
use App\Jobs\FulfillOrderItemJob;
use App\Jobs\PollPendingFulfillmentJob;
use App\Models\OrderItem;
use Illuminate\Console\Command;

class RetryFulfillmentCommand extends Command
{
    protected $signature = 'fulfillment:retry {item?} {--all-failed}';

    protected $description = 'Manually retry fulfillment for a failed order item';

    public function handle(FulfillmentProviderFactory $providerFactory): void
    {
        $itemId = $this->argument('item');
        $allFailed = $this->option('all-failed');

        if ($allFailed) {
            $items = OrderItem::where('fulfillment_status', FulfillmentStatus::Failed)->get();
            $this->info("Found {$items->count()} failed items. Re-dispatching...");

            foreach ($items as $item) {
                $this->retryItem($item, $providerFactory);
            }

            $this->info('Done.');

            return;
        }

        if (! $itemId) {
            $this->error('Please provide an item ID or use --all-failed flag.');

            return;
        }

        $item = OrderItem::find($itemId);

        if (! $item) {
            $this->error("OrderItem {$itemId} not found.");

            return;
        }

        if ($item->fulfillment_status === FulfillmentStatus::Fulfilled) {
            $this->warn("OrderItem {$itemId} is already fulfilled.");

            return;
        }

        $this->retryItem($item, $providerFactory);
    }

    /**
     * Retry one item without risking a double purchase. When the provider
     * already holds a transaction for this item (a real reference exists),
     * ask it for the truth first: anything other than a terminal failure is
     * routed through the poller, which finalizes delivery without buying
     * again. Only a confirmed-dead transaction gets a fresh purchase - with
     * a bumped retry sequence, because Zendit permanently burns the
     * transaction ID of a failed purchase.
     */
    private function retryItem(OrderItem $item, FulfillmentProviderFactory $providerFactory): void
    {
        // Refunded items are off limits: the customer already got their money
        // back for this item, so completing or re-buying it now would hand
        // over goods that were paid back. Same guard as the admin endpoint.
        $order = $item->order;
        $orderService = app(OrderService::class);
        if ($order && ($orderService->hasRefundRecord($order, "item-{$item->id}") || $orderService->hasRefundRecord($order, 'final-'.$order->id))) {
            $this->warn("Item {$item->id}: already refunded to the customer - refusing to retry. They can re-order with their wallet credit.");

            return;
        }

        $reference = (string) ($item->fulfillment_reference ?? '');

        if ($reference !== '' && ! str_starts_with($reference, 'PENDING-')) {
            try {
                $provider = $providerFactory->getProvider($item->provider_name);
                $result = $provider->verifyStatus($item);
            } catch (\InvalidArgumentException) {
                $result = null;
            }

            if ($result !== null && $result['status'] !== FulfillmentStatus::Failed) {
                $item->fulfillment_status = FulfillmentStatus::Processing;
                $item->save();

                PollPendingFulfillmentJob::dispatch($item);
                $this->info("Item {$item->id}: provider reports the original transaction is still alive - polling it instead of re-buying.");

                return;
            }
        }

        $retrySequence = (int) data_get($item->metadata, 'fulfillment_retry', 0) + 1;
        $item->metadata = array_merge($item->metadata ?? [], ['fulfillment_retry' => $retrySequence]);
        $item->save();

        FulfillOrderItemJob::dispatch($item);
        $this->info("Item {$item->id}: re-dispatched as attempt {$retrySequence}.");
    }
}
