<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * zendit:backfill-voucher-codes re-pulls codes Zendit delivered but that our
 * old normalizeResponse() dropped (Apple accountId cards). It must recover the
 * code for genuinely-DONE transactions, and must NEVER touch admin manual
 * fulfillments or transactions Zendit actually failed.
 */
class BackfillVoucherCodesTest extends TestCase
{
    use RefreshDatabase;

    private function voucherItem(string $reference, array $payload): OrderItem
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'order_number' => 'RSR-'.uniqid(),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 10.00,
            'markup_amount' => 1.00,
            'total_amount' => 11.00,
            'payment_method' => 'flutterwave',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create(['name' => 'Gift Cards', 'slug' => 'gift-cards']);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Apple']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 10.00,
            'provider_cost_usd' => 9.00,
            'markup_amount' => 1.00,
            'subtotal_amount' => 10.00,
            'product_snapshot' => ['brand_key' => 'Apple', 'name' => 'Apple', 'category' => ['slug' => 'gift-cards']],
            'fulfillment_payload' => $payload,
            'fulfillment_status' => 'fulfilled',
            'fulfillment_reference' => $reference,
        ]);
    }

    public function test_it_recovers_the_apple_account_id_code_for_a_done_transaction(): void
    {
        Http::fake([
            '*vouchers/purchases/RSR-good' => Http::response([
                'status' => 'DONE',
                'receipt' => ['accountId' => 'XQKMWHK6DR9TL7ZM', 'epin' => '', 'voucherId' => '', 'redemptionUrl' => ''],
            ], 200),
        ]);

        $item = $this->voucherItem('RSR-good', []);

        $this->artisan('zendit:backfill-voucher-codes')->assertSuccessful();

        $this->assertSame('XQKMWHK6DR9TL7ZM', $item->refresh()->fulfillment_payload['code']);
    }

    public function test_it_never_touches_manually_fulfilled_items(): void
    {
        Http::fake();

        $item = $this->voucherItem('MANUAL-6a48435d26b62', []);

        $this->artisan('zendit:backfill-voucher-codes')->assertSuccessful();

        $this->assertSame([], (array) $item->refresh()->fulfillment_payload);
        Http::assertNothingSent();
    }

    public function test_it_leaves_failed_zendit_transactions_untouched(): void
    {
        Http::fake([
            '*vouchers/purchases/RSR-failed' => Http::response([
                'status' => 'FAILED',
                'error' => ['code' => 'INSUFFICIENT_FUNDS'],
            ], 200),
        ]);

        $item = $this->voucherItem('RSR-failed', []);

        $this->artisan('zendit:backfill-voucher-codes')->assertSuccessful();

        $this->assertSame([], (array) $item->refresh()->fulfillment_payload);
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        Http::fake([
            '*vouchers/purchases/RSR-good' => Http::response([
                'status' => 'DONE',
                'receipt' => ['accountId' => 'XQKMWHK6DR9TL7ZM'],
            ], 200),
        ]);

        $item = $this->voucherItem('RSR-good', []);

        $this->artisan('zendit:backfill-voucher-codes --dry-run')->assertSuccessful();

        $this->assertSame([], (array) $item->refresh()->fulfillment_payload);
    }
}
