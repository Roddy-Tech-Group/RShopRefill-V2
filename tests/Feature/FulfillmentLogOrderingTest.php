<?php

namespace Tests\Feature;

use App\Models\FulfillmentLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The fulfillment_logs table has no created_at/updated_at columns (timestamps are
 * disabled; it tracks time via processed_at). A bare ->latest() defaults to ordering
 * by created_at, which crashed the failed-job notification path with
 * "Unknown column 'created_at' in 'order clause'". The fetch must order by
 * processed_at - the column that actually exists.
 */
class FulfillmentLogOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_tracks_time_via_processed_at_not_timestamps(): void
    {
        $this->assertTrue(Schema::hasColumn('fulfillment_logs', 'processed_at'));
        $this->assertFalse(Schema::hasColumn('fulfillment_logs', 'created_at'));
    }

    public function test_latest_log_query_orders_by_processed_at_not_created_at(): void
    {
        $sql = FulfillmentLog::where('order_item_id', 'any-id')
            ->latest('processed_at')
            ->toSql();

        $this->assertStringContainsString('"processed_at" desc', $sql);
        $this->assertStringNotContainsString('created_at', $sql);
    }
}
