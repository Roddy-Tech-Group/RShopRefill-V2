<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Fulfillment\Enums\FulfillmentStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Support\CustomerEsims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My eSIMs: every eSIM a customer bought, lifted out of their orders, with its
 * install details and top-ups, reachable from the dashboard sidebar.
 */
class MyEsimsPageTest extends TestCase
{
    use RefreshDatabase;

    private function esimItem(User $user, array $overrides = [], array $orderOverrides = []): OrderItem
    {
        $category = Category::query()->firstOrCreate(['slug' => 'esims'], ['name' => 'eSIMs', 'type' => 'catalog', 'is_active' => true]);
        $order = Order::factory()->create(array_merge(['user_id' => $user->id], $orderOverrides));

        return OrderItem::factory()->create(array_merge([
            'order_id' => $order->id,
            'category_id' => $category->id,
            'provider_name' => 'airalo',
            'product_snapshot' => ['name' => 'Japan eSIM', 'country_code' => 'JP'],
            'variant_snapshot' => ['metadata' => ['data_limit' => '5 GB', 'validity_days' => 30]],
            'fulfillment_status' => FulfillmentStatus::Fulfilled,
            'fulfillment_payload' => [
                'iccid' => '8944000000000000001',
                'qrcode_url' => 'https://example.test/qr.png',
                'lpa' => 'lpa.example.test',
                'direct_install_url' => 'https://example.test/install',
            ],
        ], $overrides));
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get(route('dashboard.esims'))->assertRedirect();
    }

    public function test_it_lists_the_customers_esims_with_plan_and_install_details(): void
    {
        $user = User::factory()->create();
        $this->esimItem($user);

        $this->withoutVite()->actingAs($user)->get(route('dashboard.esims'))
            ->assertOk()
            ->assertSee('My eSIMs')
            ->assertSee('Japan eSIM')
            ->assertSee('5 GB · 30 days')
            ->assertSee('Delivered')
            ->assertSee('8944000000000000001')
            ->assertSee('Install on iPhone')
            // The same words the fulfilment email and the top-up page itself
            // use. 'Top up data' was the one place that called it something else,
            // on the very button that leads to the page headed 'Top up your eSIM'.
            ->assertSee('Top up eSIM')
            ->assertDontSee('Top up data');
    }

    public function test_only_this_customers_esims_are_listed_and_gift_cards_are_not(): void
    {
        $user = User::factory()->create();
        $this->esimItem($user);
        $this->esimItem(User::factory()->create(), ['product_snapshot' => ['name' => 'Someone Elses eSIM']]);

        // A gift card in the same customer's orders is not an eSIM.
        OrderItem::factory()->create([
            'order_id' => Order::factory()->create(['user_id' => $user->id])->id,
            'product_snapshot' => ['name' => 'Steam Gift Card'],
            'fulfillment_payload' => ['code' => 'ABCD-1234'],
        ]);

        $names = CustomerEsims::for($user)->pluck('name')->all();

        $this->assertSame(['Japan eSIM'], $names);
    }

    public function test_top_ups_count_against_their_esim_instead_of_listing_separately(): void
    {
        $user = User::factory()->create();
        $this->esimItem($user);
        $this->esimItem($user, [
            'product_snapshot' => ['name' => 'Japan eSIM', 'is_topup' => true],
            'metadata' => ['parent_iccid' => '8944000000000000001', 'is_topup' => true],
            'fulfillment_payload' => null,
        ]);

        $esims = CustomerEsims::for($user);

        $this->assertCount(1, $esims);
        $this->assertSame(1, $esims->first()['topups']);
    }

    public function test_unpaid_and_cancelled_orders_are_left_out(): void
    {
        $user = User::factory()->create();
        $this->esimItem($user, [], ['order_status' => OrderStatus::Pending]);
        $this->esimItem($user, [], ['order_status' => OrderStatus::Cancelled]);

        $this->assertCount(0, CustomerEsims::for($user));

        $this->withoutVite()->actingAs($user)->get(route('dashboard.esims'))
            ->assertOk()
            ->assertSee('No eSIMs yet');
    }

    public function test_an_undelivered_esim_says_it_is_being_prepared(): void
    {
        $user = User::factory()->create();
        $this->esimItem($user, ['fulfillment_status' => FulfillmentStatus::Processing, 'fulfillment_payload' => null]);

        $this->withoutVite()->actingAs($user)->get(route('dashboard.esims'))
            ->assertOk()
            ->assertSee('Preparing')
            ->assertSee('We are preparing this eSIM.')
            ->assertDontSee('Install on iPhone');
    }

    public function test_the_sidebar_links_to_my_esims_right_after_orders(): void
    {
        $html = $this->withoutVite()->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();

        $orders = strpos($html, 'data-tip="Orders"');
        $esims = strpos($html, 'data-tip="My eSIMs"');

        $this->assertNotFalse($esims, 'My eSIMs is missing from the sidebar.');
        $this->assertGreaterThan($orders, $esims);
        $this->assertStringContainsString('href="'.route('dashboard.esims').'"', $html);
    }
}
