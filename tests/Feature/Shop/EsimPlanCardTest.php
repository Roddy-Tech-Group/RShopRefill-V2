<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EsimPlanCardTest extends TestCase
{
    use RefreshDatabase;

    private function card(): string
    {
        return file_get_contents(resource_path('views/shop/esim.blade.php'));
    }

    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_plan_cards_keep_the_surface_the_esim_tiles_already_had(): void
    {
        // The card is still an .esim-tile on a transparent background, so it
        // resolves through the one rule that owns the eSIM surface rather than
        // painting its own. That rule is what carries ground + grain in dark.
        $this->assertStringContainsString('class="esim-tile relative isolate flex', $this->card());
        $this->assertStringContainsString('bg-transparent', $this->card());

        preg_match('/\.dark \.esim-tile,\s*\.dark \.pure-card \{(.*?)\}/s', $this->css(), $m);
        $this->assertNotEmpty($m, 'esim-tile rule missing.');
        $this->assertStringContainsString('background-color: var(--ash) !important;', $m[1]);
        $this->assertStringContainsString('background-image: var(--grain) !important;', $m[1]);
    }

    public function test_card_carries_the_new_design_pieces(): void
    {
        $card = $this->card();

        // Allowance as one large figure, with the unit split off beside it.
        $this->assertStringContainsString('x-text="p.data_num"', $card);
        $this->assertStringContainsString('x-text="p.data_unit"', $card);

        // Unlimited plans hold a word, not a number, so they step down a size.
        $this->assertStringContainsString("p.is_unlimited ? 'text-[30px]' : 'text-[58px]'", $card);

        $this->assertStringContainsString('om-glow', $card);
        $this->assertStringContainsString('esim-plate', $card);
        $this->assertStringContainsString('Total', $card);
    }

    public function test_cta_opts_out_of_the_dark_surface_remap(): void
    {
        // `.bg-white` is remapped to the card surface in dark. The CTA has to
        // stay white there, so it is authored as dark:bg-white - a different
        // class the remap does not touch.
        $this->assertStringContainsString('dark:bg-white dark:text-zinc-900', $this->card());
    }

    public function test_spinning_badge_border_respects_reduced_motion(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('animation: om-spin', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\) \{\s*\.om-glow::before \{ animation: none; \}/',
            $css
        );
    }
}
