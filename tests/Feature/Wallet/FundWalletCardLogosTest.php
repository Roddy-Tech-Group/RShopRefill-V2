<?php

namespace Tests\Feature\Wallet;

use Tests\TestCase;

/**
 * The Visa/Mastercard logos on the fund-wallet payment modal render correctly in
 * dark mode: white chips keep the coloured SVGs legible on the dark panel, and
 * no-dark-invert stops the global image dark-mode filter from recolouring them.
 *
 * The chip used to be a plain `bg-white` inside a light ring, and the ring is
 * gone on purpose - the light redesign removed every card border. The colour
 * class moved with it, to the literal `bg-[#ffffff]`, and that is the part worth
 * knowing about: the same redesign made that literal the DASHBOARD card marker,
 * which dark mode repaints to the card grey. A markup that still said white was
 * rendering a near-black plate under a navy Visa wordmark.
 *
 * So the assertion is on `keep-light`, the escape hatch app.css declares for
 * exactly this, and not on the colour class alone. On a dashboard page that
 * class no longer means "light" by itself, and a test that only read the colour
 * would have gone on passing through the regression. DarkThemeTest owns the
 * other half - that the hatch really repaints white and wins the cascade.
 */
class FundWalletCardLogosTest extends TestCase
{
    public function test_card_logos_are_dark_mode_safe(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/dashboard/fund-wallet.blade.php'));

        // Both brand SVGs opt out of the global dark-mode image inversion.
        $this->assertStringContainsString('src="/assets/visa.svg" alt="Visa" class="no-dark-invert', $blade);
        $this->assertStringContainsString('src="/assets/mastercard.svg" alt="Mastercard" class="no-dark-invert', $blade);

        // One chip per brand, each a white plate that opts out of the dashboard
        // card flip, so the logos stay legible on the dark panel.
        $this->assertSame(
            2,
            substr_count($blade, 'rounded-[6px] keep-light bg-[#ffffff] px-2 dark:ring-1 dark:ring-white/20'),
            'Both brand chips must be keep-light white plates, or the logos land on the dark card grey.'
        );
    }
}
