<?php

namespace Tests\Feature;

use App\Domain\GiftCardTrading\Services\TradeSubmissionService;
use App\Models\GiftCardRate;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sell_gift_cards flag used to hide only the storefront links while the
 * dashboard trading flow stayed fully open: the sidebar still said "Trade
 * Gift Cards" and submissions kept landing with trading switched off.
 *
 * Off now means OFF everywhere: no dashboard links, no storefront page, no
 * search mention, a 404 submit page, and a server-side refusal at the one
 * seam every submission funnels through. Only the history and existing trade
 * pages stay reachable, so a customer with money already in a trade can
 * still follow it.
 */
class GiftCardTradingHiddenWhenOffTest extends TestCase
{
    use RefreshDatabase;

    private function setTrading(string $value): void
    {
        SiteSetting::put('features.sell_gift_cards_enabled', $value, 'features');
    }

    public function test_dashboard_hides_every_trading_link_when_off(): void
    {
        $this->setTrading('off');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Trade Gift Cards')
            ->assertDontSee('Trade Card')
            ->assertDontSee('dashboard/gift-cards/history');
    }

    public function test_the_submit_page_and_storefront_page_close_when_off(): void
    {
        $this->setTrading('off');

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard.gift-cards.submit'))->assertNotFound();
        $this->get(route('shop.sell-gift-cards'))->assertNotFound();
        $this->actingAs($user)->get(route('dashboard.shop.sell-gift-cards'))->assertNotFound();
    }

    public function test_history_stays_reachable_but_offers_no_new_trade_when_off(): void
    {
        $this->setTrading('off');

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.gift-cards.history'))
            ->assertOk()
            ->assertDontSee(route('dashboard.gift-cards.submit'));
    }

    public function test_the_storefront_search_never_mentions_selling_when_off(): void
    {
        $this->setTrading('off');

        $this->get(route('api.search.brands', ['q' => 'sell']))
            ->assertOk()
            ->assertDontSee('Sell gift cards');
    }

    public function test_the_service_refuses_a_submission_when_off(): void
    {
        $this->setTrading('off');

        $this->expectException(\DomainException::class);

        app(TradeSubmissionService::class)->submitTrade(
            userId: User::factory()->create()->id,
            rate: new GiftCardRate,
            declaredValue: 50.0,
            payoutMethod: 'wallet',
            bankAccountId: null,
            codePin: 'PIN-123',
            whatsappNumber: null,
            images: [],
        );
    }

    public function test_everything_returns_when_the_flag_is_on(): void
    {
        $this->setTrading('on');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Trade Gift Cards');

        $this->actingAs($user)->get(route('dashboard.gift-cards.submit'))->assertOk();
        $this->get(route('shop.sell-gift-cards'))->assertOk();

        $this->get(route('api.search.brands', ['q' => 'sell']))
            ->assertOk()
            ->assertSee('Sell gift cards');
    }
}
