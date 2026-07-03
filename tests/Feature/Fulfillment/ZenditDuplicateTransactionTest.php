<?php

namespace Tests\Feature\Fulfillment;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Fulfillment\Providers\ZenditFulfillmentProvider;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "transaction_duplicate_id" handling. When an auto-retry resends a transaction id
 * Zendit already holds (e.g. the first response timed out), we must NOT fail+refund -
 * we look up the existing transaction's real status, so a delivered item isn't lost.
 */
class ZenditDuplicateTransactionTest extends TestCase
{
    private function recover(string $transactionId): array
    {
        $method = new ReflectionMethod(ZenditFulfillmentProvider::class, 'recoverDuplicateTransaction');
        $method->setAccessible(true);

        return $method->invoke(new ZenditFulfillmentProvider, new OrderItem, $transactionId, false, false);
    }

    public function test_returns_fulfilled_when_existing_transaction_delivered(): void
    {
        Http::fake(['api.zendit.io/*' => Http::response(['transactionId' => 'RSR-1', 'status' => 'SUCCESS'], 200)]);

        $this->assertSame(FulfillmentStatus::Fulfilled, $this->recover('RSR-1')['status']);
    }

    public function test_stays_processing_when_lookup_is_unconfirmed(): void
    {
        // Lookup itself errors - keep it in flight, never refund a possible delivery.
        Http::fake(['api.zendit.io/*' => Http::response(['message' => 'upstream error'], 500)]);

        $this->assertSame(FulfillmentStatus::Processing, $this->recover('RSR-2')['status']);
    }

    public function test_returns_failed_when_existing_transaction_was_declined(): void
    {
        Http::fake(['api.zendit.io/*' => Http::response(['transactionId' => 'RSR-3', 'status' => 'DECLINED'], 200)]);

        $this->assertSame(FulfillmentStatus::Failed, $this->recover('RSR-3')['status']);
    }
}
