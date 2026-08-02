<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class GoogleCustomerReviewsTest extends TestCase
{
    /**
     * An in-memory completed order - no DB needed, the snippets only read
     * attributes and the (already loaded) items relation.
     */
    private function completedOrder(): Order
    {
        $order = new Order;
        $order->order_number = 'RSR-20260702-ABC123';
        $order->metadata = ['delivery_email' => 'buyer@example.com'];
        $order->completed_at = Carbon::parse('2026-07-02 10:00:00');

        $item = new OrderItem;
        $item->product_snapshot = ['country_code' => 'GB'];
        $order->setRelation('items', collect([$item]));

        return $order;
    }

    public function test_opt_in_snippet_renders_with_real_order_data(): void
    {
        config()->set('services.google_reviews.merchant_id', '5808056900');

        $html = View::make('shop._google-customer-reviews', [
            'order' => $this->completedOrder(),
        ])->render();

        $this->assertStringContainsString('surveyoptin.render', $html);
        $this->assertStringContainsString('"merchant_id": 5808056900', $html);
        $this->assertStringContainsString('RSR-20260702-ABC123', $html);
        $this->assertStringContainsString('buyer@example.com', $html);
        // delivery_country falls back to the product market when there's no
        // authenticated buyer country; estimated delivery = the completion date.
        $this->assertStringContainsString('GB', $html);
        $this->assertStringContainsString('2026-07-02', $html);
    }

    public function test_opt_in_snippet_is_hidden_when_merchant_id_is_absent(): void
    {
        config()->set('services.google_reviews.merchant_id', null);

        $html = View::make('shop._google-customer-reviews', [
            'order' => $this->completedOrder(),
        ])->render();

        $this->assertStringNotContainsString('surveyoptin', $html);
    }

    /**
     * The opt-in is no longer wired to the order confirmation page: the Google
     * survey overlay landed on top of the buyer's codes the moment the order
     * completed. The snippet itself stays (tested above) so it can be switched
     * back on with one @include.
     */
    public function test_the_order_page_no_longer_renders_the_opt_in(): void
    {
        $blade = file_get_contents(resource_path('views/shop/order.blade.php'));

        $this->assertStringNotContainsString('_google-customer-reviews', $blade);
    }

    public function test_badge_snippet_renders_when_enabled(): void
    {
        config()->set('services.google_reviews.merchant_id', '5808056900');
        config()->set('services.google_reviews.badge_enabled', true);
        config()->set('services.google_reviews.badge_position', 'BOTTOM_LEFT');

        $html = View::make('partials.google-reviews-badge')->render();

        $this->assertStringContainsString('merchantwidget.start', $html);
        $this->assertStringContainsString('"merchant_id": 5808056900', $html);
        $this->assertStringContainsString('BOTTOM_LEFT', $html);
    }

    public function test_badge_snippet_is_hidden_when_merchant_id_is_absent(): void
    {
        config()->set('services.google_reviews.merchant_id', null);

        $html = View::make('partials.google-reviews-badge')->render();

        $this->assertStringNotContainsString('merchantwidget', $html);
    }
}
