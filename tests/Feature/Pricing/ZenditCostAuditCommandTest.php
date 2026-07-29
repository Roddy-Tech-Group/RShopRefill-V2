<?php

namespace Tests\Feature\Pricing;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\FulfillmentLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins `zendit:audit-costs`, the read-only audit of Zendit cost data against
 * Zendit's documented semantics: the cost block is the USD wallet deduction,
 * so the command must surface (a) offers costing more than the face value
 * they deliver - the rows face-anchored pricing sells at a loss - and (b)
 * cost blocks labeled in a non-wallet currency, the real corruption
 * candidates. It must also lay logged transaction responses next to the
 * stored catalog cost without modifying anything.
 */
class ZenditCostAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Alfa (LB) exactly as production stores it: cost $136.18 in the wallet
     * currency, delivering a $77.28 USD face - Zendit's fx (0.567) being the
     * value-per-cost ratio, not a currency conversion.
     */
    private const ALFA_METADATA = [
        'cost' => ['fx' => 0.56748421, 'fee' => 0, 'fixed' => 13618, 'feePct' => 0, 'currency' => 'USD', 'discount' => 0.15416149, 'currencyDivisor' => 100],
        'send' => ['fx' => 1, 'fixed' => 7728, 'currency' => 'USD', 'currencyDivisor' => 100],
        'price' => ['fx' => 0.48, 'fee' => 0, 'fixed' => 16100, 'margin' => 0.15416149, 'currency' => 'USD', 'currencyDivisor' => 100],
    ];

    private function makeZenditVariant(string $productName, array $overrides = []): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-topups'], ['name' => 'Mobile Top-ups']);
        $subcategory = Subcategory::firstOrCreate(
            ['slug' => 'carriers'],
            ['category_id' => $category->id, 'name' => 'Carriers'],
        );
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'LB',
            'currency_code' => 'USD',
            'name' => $productName,
            'slug' => Str::slug($productName).'-'.uniqid(),
            'is_active' => true,
        ]);

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'sku' => strtoupper(Str::slug($productName, '_')),
            'currency' => 'USD',
            'face_value' => 77.28,
            'cost_price' => 136.18,
            'retail_price' => 77.28,
            'is_available' => true,
            'metadata' => self::ALFA_METADATA,
        ], $overrides));
    }

    private function runCommand(): string
    {
        Artisan::call('zendit:audit-costs');

        return Artisan::output();
    }

    public function test_offers_costing_more_than_their_face_are_reported(): void
    {
        $this->makeZenditVariant('Alfa (LB)');

        $output = $this->runCommand();

        $this->assertStringContainsString('Cost exceeds delivered face on 1', $output);
        $this->assertStringContainsString('Alfa (LB)', $output);
        // Cost and face side by side, plus the per-operator gap.
        $this->assertStringContainsString('136.18', $output);
        $this->assertStringContainsString('77.28', $output);
        $this->assertStringContainsString('58.90', $output);
    }

    public function test_offers_priced_at_or_under_face_are_unremarkable(): void
    {
        $this->makeZenditVariant('MTN (LR)', [
            'cost_price' => 20.51,
            'face_value' => 20.51,
            'retail_price' => 20.51,
            'metadata' => [
                'cost' => ['fx' => 1, 'fixed' => 2051, 'currency' => 'USD', 'discount' => 0, 'currencyDivisor' => 100],
                'send' => ['fx' => 1, 'fixed' => 2051, 'currency' => 'USD', 'currencyDivisor' => 100],
            ],
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('Cost exceeds delivered face on 0', $output);
        $this->assertStringContainsString('1 priced at or under face', $output);
    }

    /**
     * A cross-currency row (USD cost, NGN send) is fx working as a real
     * exchange rate. Comparing the USD cost against an NGN face would be
     * meaningless, so the row must not land in the cost-over-face set.
     */
    public function test_cross_currency_rows_are_not_compared_against_local_face(): void
    {
        $this->makeZenditVariant('Abuja Electricity (NG)', [
            'cost_price' => 12.06,
            'face_value' => 15600.00,
            'retail_price' => 12.06,
            'metadata' => [
                'cost' => ['fx' => 1293.53233831, 'fixed' => 1206, 'currency' => 'USD', 'discount' => 0.0017, 'currencyDivisor' => 100],
                'send' => ['fx' => 1, 'fixed' => 1560000, 'currency' => 'NGN', 'currencyDivisor' => 100],
            ],
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('Cost exceeds delivered face on 0', $output);
        $this->assertStringContainsString('Wallet-currency violations: 0', $output);
    }

    /**
     * Zendit documents the cost block as always wallet-currency (USD); a row
     * labeled otherwise is the real corruption candidate and must be listed.
     */
    public function test_non_wallet_cost_currency_is_reported_as_a_violation(): void
    {
        $this->makeZenditVariant('Mislabeled Gift Card (AE)', [
            'metadata' => [
                'cost' => ['fx' => 1, 'fixed' => 3900, 'currency' => 'AED', 'currencyDivisor' => 100],
                'send' => ['fx' => 1, 'fixed' => 3500, 'currency' => 'AED', 'currencyDivisor' => 100],
            ],
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('Wallet-currency violations: 1', $output);
        $this->assertStringContainsString('Mislabeled Gift Card (AE)', $output);
        $this->assertStringContainsString('AED', $output);
    }

    /**
     * The eSIM normalizer nests the raw offer under metadata.raw_payload and
     * ships no send block (price is the counterpart). Those variants must not
     * escape the census.
     */
    public function test_esim_variants_nested_under_raw_payload_are_audited(): void
    {
        $this->makeZenditVariant('Zendit eSIM (LB)', [
            'metadata' => [
                'provider' => 'zendit',
                'raw_payload' => [
                    'cost' => ['fx' => 0.56748421, 'fixed' => 13618, 'currency' => 'USD', 'discount' => 0.15416149, 'currencyDivisor' => 100],
                    'price' => ['fx' => 1, 'fixed' => 7728, 'currency' => 'USD', 'currencyDivisor' => 100],
                ],
            ],
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('Zendit variants with a cost block: 1', $output);
        $this->assertStringContainsString('Cost exceeds delivered face on 1', $output);
    }

    public function test_non_zendit_products_are_ignored(): void
    {
        $variant = $this->makeZenditVariant('Airalo Thing');
        $variant->product->update(['provider_name' => 'airalo']);

        $output = $this->runCommand();

        $this->assertStringContainsString('Zendit variants with a cost block: 0', $output);
    }

    public function test_transaction_evidence_lists_payload_money_fields_next_to_the_stored_cost(): void
    {
        $variant = $this->makeZenditVariant('Alfa (LB)');
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.Str::upper(Str::random(8)),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 78.83,
            'markup_amount' => 0,
            'total_amount' => 78.83,
            'payment_method' => 'wallet',
            'payment_status' => PaymentStatus::Paid,
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'order_status' => OrderStatus::Completed,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'subcategory_id' => $variant->product->subcategory_id,
            'provider_name' => 'zendit',
            'provider_offer_id' => $variant->provider_offer_id,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 78.83,
            'provider_cost_usd' => 136.18,
            'markup_amount' => 0,
            'subtotal_amount' => 78.83,
            'product_snapshot' => ['name' => 'Alfa (LB)'],
            'variant_snapshot' => ['face_value' => 77.28, 'currency' => 'USD'],
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'delivered_at' => now(),
        ]);

        FulfillmentLog::create([
            'order_item_id' => $item->id,
            'provider_name' => 'zendit',
            'request_payload' => ['offerId' => $variant->provider_offer_id],
            'response_payload' => [
                'transactionId' => 'ZTX-1',
                'status' => 'DONE',
                'cost' => ['fixed' => 13618, 'fx' => 0.56748421, 'currency' => 'USD'],
                // Numbers serialized as strings must still surface: the purchase
                // response's cost fields are the deduction Zendit reported.
                'costBase' => '136.18',
                'recipient' => ['name' => 'ignored'],
            ],
            'status' => 'SUCCESS',
            'processed_at' => now(),
        ]);

        $output = $this->runCommand();

        $this->assertStringContainsString('TRANSACTION EVIDENCE', $output);
        $this->assertStringContainsString('cost.fixed = 13618', $output);
        $this->assertStringContainsString('cost.fx = 0.56748421', $output);
        $this->assertStringContainsString('cost.currency = USD', $output);
        $this->assertStringContainsString('costBase = 136.18', $output);
        // Stored snapshot, current catalog reading, face and the customer charge on one line.
        $this->assertStringContainsString('stored cost at sale $136.18 | catalog cost now $136.18 | face $77.28 | charged customer $78.83', $output);
        // Non-money noise stays out.
        $this->assertStringNotContainsString('recipient.name', $output);
    }

    public function test_no_logged_transactions_is_stated_honestly(): void
    {
        $this->makeZenditVariant('Alfa (LB)');

        $this->assertStringContainsString('No Zendit fulfillment responses logged yet', $this->runCommand());
    }
}
