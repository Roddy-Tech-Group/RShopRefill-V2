<?php

namespace Tests\Feature\Wallet;

use Tests\TestCase;

/**
 * The Visa/Mastercard logos on the fund-wallet payment modal render correctly in
 * dark mode: white chips keep the coloured SVGs legible on the dark panel, and
 * no-dark-invert stops the global image dark-mode filter from recolouring them.
 */
class FundWalletCardLogosTest extends TestCase
{
    public function test_card_logos_are_dark_mode_safe(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/dashboard/fund-wallet.blade.php'));

        // Both brand SVGs opt out of the global dark-mode image inversion.
        $this->assertStringContainsString('src="/assets/visa.svg" alt="Visa" class="no-dark-invert', $blade);
        $this->assertStringContainsString('src="/assets/mastercard.svg" alt="Mastercard" class="no-dark-invert', $blade);

        // The logos sit on white chips so they stay legible on the dark panel.
        $this->assertStringContainsString('bg-white px-2 ring-1 ring-zinc-200 dark:ring-white/20', $blade);
    }
}
