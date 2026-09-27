<?php

namespace Tests\Feature\Shop;

use App\Domain\Payment\Support\PaymentStripBadges;
use App\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * How it works, step 2: the network illustration links checkout to the
 * payment marks from the "Payment Methods We Accept" strip, read from the same
 * PaymentStripBadges list, instead of abstract shapes.
 */
class PayWebIllustrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The migrations seed the launch line-up of coins. Each case here
        // states exactly which coins exist, so it starts from none.
        CurrencyRate::query()->delete();
    }

    private function render(): string
    {
        PaymentStripBadges::forgetCryptoCache();

        return Blade::render('<x-illo name="payWeb" />');
    }

    private function coin(string $code, string $name, string $icon, int $sort): void
    {
        CurrencyRate::create([
            'code' => $code,
            'name' => $name,
            'type' => 'crypto',
            'rate_per_usd' => 1,
            'icon_path' => $icon,
            'sort_order' => $sort,
            'is_active' => true,
            'is_payable' => true,
        ]);
    }

    public function test_the_nodes_use_the_strips_own_logo_files(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', 1);
        $this->coin('USDT', 'Tether', 'USDT.svg', 2);

        $svg = $this->render();

        foreach (['visa.svg', 'mastercard.svg', rawurlencode('MTN MOMO.png'), 'BTC.svg', 'USDT.svg'] as $file) {
            $this->assertStringContainsString('href="'.asset('assets/'.$file).'"', $svg, $file.' is not drawn.');
        }

        // The inline marks are the strip's own: Orange Money's colour, the bank glyph's roof.
        $this->assertStringContainsString('fill="#FF7900"', $svg);
        $this->assertStringContainsString('M3.25 9.5 12 4.25l8.75 5.25', $svg);

        // Nine nodes and nine links, so the app.js dots still pair one to one.
        $this->assertSame(9, substr_count($svg, 'class="ipay-node"'));
        $this->assertSame(9, substr_count($svg, 'class="ipay-link"'));
        $this->assertSame(9, substr_count($svg, 'class="ipay-dot"'));
        $this->assertStringContainsString('id="ipay-hub"', $svg);
    }

    /** A coin checkout no longer accepts is gone from the picture too. */
    public function test_an_unpayable_coin_is_not_drawn(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', 1);
        CurrencyRate::create([
            'code' => 'ETH', 'name' => 'Ethereum', 'type' => 'crypto', 'rate_per_usd' => 1,
            'icon_path' => 'ETH.svg', 'sort_order' => 2, 'is_active' => true, 'is_payable' => false,
        ]);

        $svg = $this->render();

        $this->assertStringContainsString('BTC.svg', $svg);
        $this->assertStringNotContainsString('ETH.svg', $svg);
        $this->assertStringNotContainsString('data-pay-mark="none"', $svg, 'A missing coin should be filled by another accepted mark.');
    }

    /** With no coins at all, the fallback marks still fill every node. */
    public function test_every_node_is_filled_without_any_coins(): void
    {
        $svg = $this->render();

        $this->assertSame(9, substr_count($svg, 'class="ipay-node"'));
        $this->assertStringNotContainsString('data-pay-mark="none"', $svg);
        $this->assertStringNotContainsString('data-pay-mark="coin"', $svg);
    }

    /** Screen readers hear the methods shown, not a vague "Pay your way". */
    public function test_the_accessible_name_lists_the_methods(): void
    {
        $this->coin('BTC', 'Bitcoin', 'BTC.svg', 1);

        $svg = $this->render();

        $this->assertMatchesRegularExpression('/aria-label="Pay with Visa, Mastercard, MoMo, Bitcoin, Orange Money, [^"]+"/', $svg);
    }
}
