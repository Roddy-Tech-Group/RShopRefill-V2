<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Inside the dashboard the shop listings run as wide as the overview.
 *
 * The dashboard frame pads every page itself, 16px a side on a phone, and
 * the overview fills that. The listings also padded their own wrapper, so
 * inside the frame they sat 16px narrower each side with doubled vertical
 * space. In the dashboard the wrapper now adds no padding; the storefront,
 * which has no frame, keeps its own.
 */
class ShopListingsMatchOverviewWidthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function listings(): array
    {
        return [
            'gift cards' => ['dashboard.shop.gift-cards'],
            'top-ups' => ['dashboard.shop.topups'],
            'bills' => ['dashboard.shop.bills'],
            'esims' => ['dashboard.shop.esims'],
        ];
    }

    #[DataProvider('listings')]
    public function test_in_the_dashboard_the_listing_wrapper_leaves_the_padding_to_the_frame(string $route): void
    {
        $html = $this->withoutVite()
            ->actingAs(User::factory()->create())
            ->get(route($route))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/class="mx-auto w-full max-w-\[1550px\]\s*"/',
            $html,
            'the listing wrapper should carry no padding of its own in the dashboard'
        );
        $this->assertStringNotContainsString('max-w-[1550px] px-4', $html);

        // The frame pads the page, the same as it does for the overview.
        $this->assertStringContainsString('px-4 pt-5 pb-10 sm:px-6', $html);
    }

    public function test_on_the_storefront_the_listing_wrapper_keeps_its_own_padding(): void
    {
        $html = $this->withoutVite()->get(route('shop.gift-cards'))->assertOk()->getContent();

        $this->assertStringContainsString('class="mx-auto w-full max-w-[1550px] px-4 py-8 sm:px-6 lg:px-8"', $html);
    }
}
