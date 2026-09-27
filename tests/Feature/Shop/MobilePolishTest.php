<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * A batch of measured layout fixes. Each assertion below names the number that
 * was measured in a real browser at 390px, so a future edit that undoes one can
 * be recognised as undoing it rather than as a tidy-up.
 */
class MobilePolishTest extends TestCase
{
    public function test_trust_counts_sit_on_one_row_on_a_phone(): void
    {
        $markup = file_get_contents(resource_path('views/components/home/customer-counts.blade.php'));

        // The three figures are one claim read together. flex-wrap stacked them
        // into three separate rows at 390px; a 3-column grid keeps them level,
        // and the original centred flex row returns from sm.
        $this->assertStringContainsString('grid grid-cols-3', $markup);
        $this->assertStringContainsString('sm:flex sm:flex-wrap', $markup);
    }

    public function test_the_phone_logo_is_the_r_alone(): void
    {
        $markup = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        // <picture> rather than two <img> tags: both would download, and this is
        // a fetchpriority=high image in the header.
        $this->assertStringContainsString('<picture', $markup);
        $this->assertStringContainsString('media="(min-width: 768px)"', $markup);
        $this->assertStringContainsString('PWAicon.webp', $markup);
    }

    public function test_the_nav_collapses_sooner_without_reopening_the_flicker_loop(): void
    {
        $markup = file_get_contents(resource_path('views/components/nav/main-nav.blade.php'));

        preg_match('/const SHOW_AT = (\d+);/', $markup, $show);
        preg_match('/const HIDE_AT = (\d+);/', $markup, $hide);

        $this->assertNotEmpty($show);
        $this->assertNotEmpty($hide);

        $showAt = (int) $show[1];
        $hideAt = (int) $hide[1];

        // Collapsing shortens the header, which pulls the page up under the
        // cursor. If that lands back under SHOW_AT the bar reopens and the nav
        // flickers. The two rows give back about 100px, so the gap has to clear
        // it - this is the invariant, not the specific numbers.
        $this->assertGreaterThan(100, $hideAt - $showAt, 'The show/hide gap must clear the ~100px the collapse removes.');
        $this->assertLessThanOrEqual(150, $hideAt, 'The nav should give its space back early in the scroll.');
    }

    public function test_the_loading_bar_is_not_blue_in_dark_mode(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Brand blue on near-black is the lowest-contrast pairing on the site,
        // and this is a 3px line at the very top of the screen.
        $this->assertStringContainsString('html.dark #nprogress .bar', $css);
        $this->assertStringContainsString('background: #e5e5e5 !important;', $css);
    }

    public function test_form_text_cannot_trigger_ios_zoom(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // iOS Safari zooms the whole page in when a focused field's text is
        // under 16px, and does not zoom back out on blur. That is what "the page
        // grows when I type" was: the nav search sat at 13px and the inquiry
        // forms at 14px.
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\) \{\s*input:not/',
            $css,
            'The 16px floor for form controls should be the first rule in its mobile media block.'
        );
    }

    public function test_the_gift_card_faq_runs_on_from_the_row_above_it(): void
    {
        $page = file_get_contents(resource_path('views/shop/gift-cards.blade.php'));
        $faq = file_get_contents(resource_path('views/components/home/faq.blade.php'));

        // Measured at 160px of empty page between the last "See more" and the
        // first question: mb-16 on the row plus the FAQ's own lg:mt-16.
        $this->assertStringContainsString('top-margin=""', $page);
        $this->assertStringNotContainsString('class="mt-20 mb-16"', $page);

        // The margin is a prop so this page can drop it without changing the
        // spacing every other FAQ caller relies on.
        $this->assertStringContainsString("'topMargin' => 'mt-12 lg:mt-16'", $faq);
    }
}
