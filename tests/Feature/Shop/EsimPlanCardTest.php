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

    /**
     * A plan that carries a number says what the number is for, on the card:
     * the messaging apps are the reason to pay for one over a data-only plan.
     * Both chips are gated on the same is_voice the "Phone number" chip uses,
     * so a data-only plan - which has no number to verify against - never
     * shows them.
     */
    public function test_a_plan_with_a_number_names_the_messaging_apps(): void
    {
        $card = $this->card();

        foreach (['WhatsApp', 'iMessage'] as $app) {
            $this->assertMatchesRegularExpression(
                '/<template x-if="p\.is_voice">\s*<span class="\{\{ \$planChip \}\}">'.$app.'<\/span>\s*<\/template>/',
                $card,
                "The {$app} chip is missing, or is not gated on the plan carrying a number."
            );
        }

        // The claim is the one the voice tab already makes below the cards, so
        // the card and the panel under it cannot say different things.
        $this->assertStringContainsString("'title' => 'App verification', 'desc' => 'WhatsApp,", $card);
    }

    /**
     * A rechargeable plan says so on its own card, from the same flag the
     * page-level note counts. That note shows whenever ANY plan on the
     * destination can be topped up, so where most can and a few cannot it is
     * true of the destination and wrong about the card being read.
     */
    public function test_a_rechargeable_plan_says_so_on_its_card(): void
    {
        $card = $this->card();

        $this->assertMatchesRegularExpression(
            '/<template x-if="p\.top_up">\s*<span class="\{\{ \$planChip \}\}">Top-up<\/span>\s*<\/template>/',
            $card,
            'The Top-up chip is missing, or is not gated on the plan being rechargeable.'
        );

        // Both the chip and the note read the one flag the plan array carries.
        $this->assertStringContainsString("'top_up'       => (bool) (\$meta['is_rechargeable'] ?? (\$meta['supports_topup'] ?? false)),", $card);
        $this->assertStringContainsString("\$anyTopUp = \$plans->contains(fn (\$p) => \$p['top_up']);", $card);
    }

    public function test_plan_cards_keep_the_surface_the_esim_tiles_already_had(): void
    {
        // The card is still an .esim-tile, so it resolves through the one rule
        // that owns the eSIM surface rather than painting its own in dark. That
        // rule is what carries ground + grain there.
        //
        // This used to also assert `bg-transparent` against the whole file,
        // which was never the card: the card is bg-[#ffffff], and the string was
        // being matched on an unrelated control elsewhere in the same view. The
        // claim is measured on the card's own tag now, so it cannot pass on a
        // neighbour again.
        preg_match('/<button[^>]*class="esim-tile relative isolate flex[^"]*"/', $this->card(), $tag);
        $this->assertNotEmpty($tag, 'The plan card did not render.');

        $this->assertStringContainsString('esim-tile', $tag[0]);
        $this->assertStringContainsString('bg-[#ffffff]', $tag[0]);

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
