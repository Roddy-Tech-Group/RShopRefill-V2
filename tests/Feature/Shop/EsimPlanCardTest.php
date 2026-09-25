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

        // The claim is the one the voice tab already makes below the cards
        // ("Every voice plan includes": app verification, then iMessage), so
        // the card and the panel under it cannot say different things.
        $this->assertStringContainsString("['title' => __('esim.voice_app_title'), 'desc' => __('esim.voice_app_desc')", $card);
        $this->assertStringContainsString("['title' => __('esim.voice_imessage_title'), 'desc' => __('esim.voice_imessage_desc')", $card);
        $this->assertStringContainsString('WhatsApp', __('esim.voice_app_desc'));
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
            '/<template x-if="p\.top_up">\s*<span class="\{\{ \$planChip \}\}">\{\{ __\(\'esim\.chip_top_up\'\) \}\}<\/span>\s*<\/template>/',
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

    public function test_card_carries_the_current_design_pieces(): void
    {
        $card = $this->card();

        // The allowance as one line: the amount, or the word for unlimited.
        $this->assertStringContainsString('x-text="p.is_unlimited ? @js(__(\'products.detail_unlimited_data\')) : p.data"', $card);

        // Every card names its family in the badge slot, Calls or Data, and
        // the two are mutually exclusive so a card never shows both.
        $this->assertMatchesRegularExpression('/<template x-if="p\.is_voice">\s*<span class="shrink-0 rounded-full bg-\[#0c1337\][^"]*">\{\{ __\(\'esim\.badge_calls\'\) \}\}<\/span>/', $card);
        $this->assertMatchesRegularExpression('/<template x-if="! p\.is_voice">\s*<span class="shrink-0 rounded-full bg-\[#0c1337\][^"]*">\{\{ __\(\'esim\.chip_data\'\) \}\}<\/span>/', $card);

        // The price sits at the foot, then the action pill.
        $this->assertStringContainsString('x-text="rowPrice(p)"', $card);
        $this->assertStringContainsString("{{ __('esim.get_esim') }}</span>", $card);

        // The old plate, glow and 58px figure are gone from the card.
        foreach (['x-text="p.data_num"', 'om-glow', 'esim-plate', 'text-[58px]'] as $old) {
            $this->assertStringNotContainsString($old, $card, $old.' is back on the card');
        }
    }

    public function test_cta_is_brand_navy_and_flips_to_its_own_white_in_dark(): void
    {
        // The card's action is the store's pill in brand navy. In dark it is
        // authored as its own white (dark:bg-[#fdfdfd]) rather than bg-white,
        // which the dark remap would turn into the card surface.
        $this->assertStringContainsString('rounded-full bg-[#0c1337] px-3 py-2 text-center text-[14px] text-white transition-colors hover:bg-[#1a2560] dark:bg-[#fdfdfd]', $this->card());
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
