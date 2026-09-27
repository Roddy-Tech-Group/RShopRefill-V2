<?php

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A completed order invites a public review right under the delivered card:
 * the review platform's collector widget (the TrustBox), between the gift
 * card with its help links and our own "share your experience" form.
 */
class OrderPageTrustBoxTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, string $orderStatus = 'completed'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-'.strtoupper(uniqid()),
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 25.00,
            'markup_amount' => 2.00,
            'total_amount' => 27.00,
            'payment_method' => 'wallet',
            'payment_status' => 'paid',
            'fulfillment_status' => $orderStatus === 'completed' ? 'fulfilled' : 'not_started',
            'order_status' => $orderStatus,
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0],
        ]);

        $category = Category::factory()->create();
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);
        $product = Product::factory()->create(['category_id' => $category->id, 'brand_key' => 'Netflix']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'test',
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 25.00,
            'provider_cost_usd' => 22.00,
            'markup_amount' => 2.00,
            'subtotal_amount' => 25.00,
            'product_snapshot' => ['brand_key' => 'Netflix', 'name' => 'Netflix Gift Card', 'country_code' => 'US'],
            'variant_snapshot' => ['face_value' => 25, 'currency' => 'USD'],
            'fulfillment_payload' => $orderStatus === 'completed' ? ['code' => 'NFLX-TEST-CODE-1234'] : null,
            'fulfillment_status' => $orderStatus === 'completed' ? 'fulfilled' : 'not_started',
        ]);

        return $order;
    }

    private function page(User $user, Order $order, ?string $locale = null): string
    {
        $request = $this->withoutVite()->actingAs($user);

        if ($locale !== null) {
            $request = $request->withSession(['locale' => $locale]);
        }

        return $request->get(route('shop.order', $order->order_number))->assertOk()->getContent();
    }

    public function test_the_collector_sits_between_the_gift_card_and_the_native_form(): void
    {
        $user = User::factory()->create();
        $html = $this->page($user, $this->order($user));

        // Page-sized haystacks are asserted tersely.
        $card = strpos($html, 'theme-static mx-auto max-w-[340px]');
        $trustbox = strpos($html, 'data-trustbox');
        $helpLinks = strpos($html, __('orders.redeem_instructions'));
        $form = strpos($html, route('reviews.store'));

        $this->assertNotFalse($card, 'the delivered gift card is missing');
        $this->assertNotFalse($trustbox, 'the collector card is missing');
        $this->assertNotFalse($form, 'the native review form is missing');
        $this->assertTrue($card < $helpLinks && $helpLinks < $trustbox, 'the collector must come after the gift card and its help links');
        $this->assertTrue($trustbox < $form, 'the collector must come before the native form');
    }

    public function test_the_widget_carries_the_configured_ids_and_its_bootstrap(): void
    {
        $user = User::factory()->create();
        $html = $this->page($user, $this->order($user));
        $tp = config('services.trustpilot');

        foreach ([
            'src="//widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js" async',
            'class="trustpilot-widget"',
            'data-template-id="'.$tp['review_collector_template_id'].'"',
            'data-businessunit-id="'.$tp['business_unit_id'].'"',
            'data-token="'.$tp['review_collector_token'].'"',
            'href="'.$tp['profile_url'].'" target="_blank" rel="noopener"',
            __('reviews.review_fallback'),
            __('reviews.collector_body'),
        ] as $needle) {
            $this->assertTrue(str_contains($html, $needle), "the collector lacks: {$needle}");
        }
    }

    /**
     * The title names no platform: the aggregate-source setting reads
     * "Trustpilot, Google" and the widget is for one of them, whose own
     * branding inside the TrustBox is the only naming their guidelines want.
     */
    public function test_the_title_is_the_plain_invitation_in_the_readers_language(): void
    {
        $user = User::factory()->create();
        $html = $this->page($user, $this->order($user), 'fr');

        $this->assertTrue(str_contains($html, '<h2 class="mt-4 text-lg font-bold text-zinc-900 dark:text-white">'.__('reviews.review_us', [], 'fr').'</h2>'), 'the French title is missing');
        $this->assertFalse(str_contains($html, 'Review us on Trustpilot'), 'the title must not read the aggregate source setting');
    }

    public function test_an_order_still_in_progress_does_not_ask_for_a_review(): void
    {
        $user = User::factory()->create();
        $html = $this->page($user, $this->order($user, 'processing'));

        $this->assertFalse(str_contains($html, 'data-trustbox'), 'a review is asked for before delivery');
        $this->assertFalse(str_contains($html, 'tp.widget.bootstrap.min.js'), 'the widget bootstrap loads on a page without the widget');
    }
}
