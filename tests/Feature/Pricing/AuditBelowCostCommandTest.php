<?php

namespace Tests\Feature\Pricing;

use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pins `pricing:audit-below-cost` — the Stage 0 rollout gate. The command must
 * surface each finding class in its own section, flag suspiciously large
 * would-be raises as corrupt cost data, and exit non-zero only when money is
 * actively bleeding (below-cost sales or unguarded below-cost overrides).
 */
class AuditBelowCostCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'pricing.safety_markup_percent' => 10,
            'pricing.min_margin_percent' => 1,
            'pricing.floor_mode' => 'legacy',
        ]);

        // 2% markup on everything — small enough that a cost above face
        // produces a below-cost sale.
        PricingRule::create(['markup_type' => 'percentage', 'markup_value' => 2, 'is_active' => true]);
    }

    private function makeVariant(string $name, array $overrides = []): ProductVariant
    {
        $category = Category::firstOrCreate(['slug' => 'mobile-topups'], ['name' => 'Mobile Top-ups']);
        $subcategory = Subcategory::firstOrCreate(
            ['slug' => 'us-carriers'],
            ['category_id' => $category->id, 'name' => 'US Carriers'],
        );
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'is_active' => true,
        ]);

        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'provider_offer_id' => 'offer-'.uniqid(),
            'currency' => 'USD',
            'face_value' => 45.00,
            'cost_price' => 46.35,
            'retail_price' => 45.00,
            'is_available' => true,
        ], $overrides));
    }

    public function test_flags_below_cost_variants_and_exits_with_failure(): void
    {
        $this->makeVariant('Straight Talk US'); // face 45 x 1.02 = 45.90 < cost 46.35

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('Straight Talk US')
            ->expectsOutputToContain('1 BELOW-COST')
            ->assertExitCode(1);
    }

    public function test_healthy_catalog_reports_clean_and_exits_successfully(): void
    {
        $this->makeVariant('Apple Gift Card', ['face_value' => 10.00, 'cost_price' => 9.50, 'retail_price' => 10.00]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('0 BELOW-COST')
            ->assertExitCode(0);
    }

    public function test_flags_suspect_cost_data_on_oversized_would_be_raises(): void
    {
        // COST-IN-LOCAL shape: an AED-cost card mislabeled USD. Cost 97.89 vs
        // a ~$26 price — the floor would raise the price ~270%, which must be
        // reported as suspect data, not as a legitimate reprice.
        $this->makeVariant('Careem AED Card', [
            'face_value' => 26.00,
            'cost_price' => 97.89,
            'retail_price' => 26.00,
        ]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('SUSPECT-COST')
            ->expectsOutputToContain('1 suspect cost data')
            ->assertExitCode(1); // it is also below cost today
    }

    public function test_flags_manual_overrides_under_the_cost_floor(): void
    {
        // Override keeps the variant off the BELOW-COST list only if priced
        // right; here the override itself is the leak.
        $this->makeVariant('Verizon US', [
            'face_value' => 50.00,
            'cost_price' => 48.00,
            'retail_price' => 50.00,
            'manual_retail_price_usd' => 45.00,
        ]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('MANUAL OVERRIDES UNDER THE COST FLOOR')
            ->expectsOutputToContain('1 OVERRIDE under floor')
            ->assertExitCode(1);
    }

    public function test_override_flagged_allow_below_cost_is_not_reported(): void
    {
        $this->makeVariant('Deliberate Loss Leader', [
            'face_value' => 50.00,
            'cost_price' => 48.00,
            'retail_price' => 50.00,
            'manual_retail_price_usd' => 45.00,
            'allow_below_cost' => true,
        ]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('0 OVERRIDE under floor')
            ->assertExitCode(1); // still below cost at charge time (flag honours it) — BELOW-COST reports it
    }

    public function test_reports_paid_order_units_sold_below_cost(): void
    {
        $variant = $this->makeVariant('Healthy SKU', ['face_value' => 10.00, 'cost_price' => 9.00, 'retail_price' => 10.00]);

        // Paid order in XAF: revenue 2500 XAF at rate 500 = $5.00 against $8 cost.
        $order = Order::factory()->create([
            'payment_status' => PaymentStatus::Paid,
            'display_currency' => 'XAF',
            'metadata' => ['exchange_rate' => 500.0],
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'subcategory_id' => $variant->product->subcategory_id,
            'display_currency' => 'XAF',
            'display_amount' => 2500.00,
            'subtotal_amount' => 2500.00,
            'provider_cost_usd' => 8.00,
        ]);

        // An UNPAID below-cost order must not count — nothing was charged.
        $unpaid = Order::factory()->create([
            'payment_status' => PaymentStatus::Unpaid,
            'metadata' => ['exchange_rate' => 1.0],
        ]);
        OrderItem::factory()->create([
            'order_id' => $unpaid->id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'category_id' => $variant->product->category_id,
            'subcategory_id' => $variant->product->subcategory_id,
            'subtotal_amount' => 1.00,
            'provider_cost_usd' => 8.00,
        ]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('1 paid order units below cost')
            ->assertExitCode(0); // catalog itself is healthy
    }

    public function test_unavailable_variants_are_skipped_unless_all_flag(): void
    {
        $this->makeVariant('Retired SKU', ['is_available' => false]);

        $this->artisan('pricing:audit-below-cost')
            ->expectsOutputToContain('0 BELOW-COST')
            ->assertExitCode(0);

        $this->artisan('pricing:audit-below-cost', ['--all' => true])
            ->expectsOutputToContain('1 BELOW-COST')
            ->assertExitCode(1);
    }
}
