<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inside the dashboard the cart and checkout run as wide as every other page.
 *
 * The frame pads the page itself, 16px a side on a phone, and the listings and
 * the product page leave that padding to it. The cart and checkout padded
 * their own wrapper as well, so the two pages at the end of the buying flow
 * sat 16px narrower each side than the pages that led into them.
 *
 * On the storefront there is no frame, so there the wrapper keeps its padding.
 */
class CartAndCheckoutMatchPageWidthTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function pages(): array
    {
        return [
            'cart' => ['dashboard.shop.cart', 'shop.cart'],
            'checkout' => ['dashboard.shop.checkout', 'shop.checkout'],
        ];
    }

    #[DataProvider('pages')]
    public function test_in_the_dashboard_the_wrapper_leaves_the_padding_to_the_frame(string $dashRoute, string $shopRoute): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route($dashRoute))
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            str_contains($html, 'class="mx-auto w-full max-w-7xl "'),
            'the wrapper should carry no padding of its own in the dashboard'
        );
        $this->assertFalse(
            str_contains($html, 'max-w-7xl px-4 py-6 sm:px-6'),
            'the wrapper is still padding on top of the frame'
        );

        // The frame pads the page, the same as it does for every other page.
        $this->assertTrue(str_contains($html, 'px-4 pt-5 pb-10 sm:px-6'), 'the frame is not padding the page');
    }

    #[DataProvider('pages')]
    public function test_on_the_storefront_the_wrapper_keeps_its_own_padding(string $dashRoute, string $shopRoute): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route($shopRoute))
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            str_contains($html, 'class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-10"'),
            'the storefront has no frame, so the wrapper must pad itself'
        );
    }

    /**
     * The product page is where this pattern already lived, and the cart it
     * leads into should not read narrower than it.
     */
    public function test_the_buying_flow_is_one_width_from_product_to_checkout(): void
    {
        $user = User::factory()->create();
        $widths = [];

        foreach (['dashboard.shop.gift-cards', 'dashboard.shop.cart', 'dashboard.shop.checkout'] as $route) {
            $html = $this->withoutVite()->actingAs($user)->get(route($route))->assertOk()->getContent();

            // Whatever each page caps itself at, none of them may add a gutter
            // of their own on top of the frame's.
            $this->assertDoesNotMatchRegularExpression(
                '/class="mx-auto w-full max-w-[^"]*\bpx-4\b/',
                $html,
                $route.' adds a gutter on top of the frame'
            );
            $widths[$route] = str_contains($html, 'px-4 pt-5 pb-10 sm:px-6');
        }

        $this->assertSame([true, true, true], array_values($widths), 'every page should be padded by the frame');
    }
}
