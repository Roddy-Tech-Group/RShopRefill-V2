<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The review collector widget, the contact page's support buttons and the nav
 * search field, as they were fixed together.
 *
 * The widget draws a 38px button at the top of whatever frame it is given, so
 * its frame is exactly that tall and sits centred in a 52px white box. The box
 * is a literal white: dark mode remaps bg-white to its grey, which left the
 * widget's own white strip floating in a grey box.
 */
class ReviewWidgetAndContactButtonsTest extends TestCase
{
    use RefreshDatabase;

    private const WIDGET_VIEWS = [
        'components/home/customer-reviews.blade.php',
        'shop/contact.blade.php',
        'shop/order.blade.php',
        'shop/reviews.blade.php',
    ];

    private const FRAME = 'review-collector-frame inline-flex h-[52px] items-center overflow-hidden rounded-[12px] bg-[#ffffff] shadow-md';

    public function test_every_widget_sits_centred_in_a_white_box_the_same_way(): void
    {
        foreach (self::WIDGET_VIEWS as $view) {
            $source = File::get(resource_path('views/'.$view));

            $this->assertTrue(str_contains($source, self::FRAME), "{$view}: the widget box lost its size, centring or literal white");
            $this->assertTrue(str_contains($source, 'data-style-height="38px"'), "{$view}: the widget frame is not the button's height");
            $this->assertFalse(str_contains($source, 'data-style-height="52px"'), "{$view}: the old frame height leaves a gap under the button");
        }
    }

    public function test_the_pages_render_the_current_widget_token(): void
    {
        $token = config('services.trustpilot.review_collector_token');
        $this->assertSame('729d50a6-9fed-4135-b791-92b14b8f875f', $token);

        foreach ([route('shop.contact'), route('shop.reviews')] as $url) {
            $html = $this->withoutVite()->get($url)->assertOk()->getContent();

            $this->assertTrue(str_contains($html, 'data-token="'.$token.'"'), "{$url} does not render the current widget token");
            $this->assertTrue(str_contains($html, self::FRAME), "{$url} does not render the centred white box");
        }
    }

    public function test_widgets_reached_by_in_place_navigation_are_built(): void
    {
        // The loader only builds widgets on its first run; the hook builds
        // any widget a later in-place navigation brings in without a frame.
        $js = File::get(resource_path('js/app.js'));

        $this->assertMatchesRegularExpression(
            "/document\.addEventListener\('livewire:navigated', \(\) => \{\s*if \(reviewWidgetsFirstVisit\) \{/",
            $js,
        );
        $this->assertTrue(str_contains($js, "document.querySelectorAll('.trustpilot-widget').forEach((widget) => {"), 'the hook no longer walks the widgets');
        $this->assertTrue(str_contains($js, "if (!widget.querySelector('iframe')) {\n            loader.loadFromElement(widget, true);"), 'the hook must only build widgets that are still empty');
    }

    public function test_the_support_buttons_are_solid_so_dark_mode_cannot_flatten_them(): void
    {
        $html = $this->withoutVite()->get(route('shop.contact'))->assertOk()->getContent();

        foreach ([
            'aria-label="Call global support" class="flex items-center justify-center gap-1.5 rounded-full bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"',
            'aria-label="iMessage global support" class="flex items-center justify-center gap-1.5 rounded-full bg-blue-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-blue-700"',
            'aria-label="WhatsApp global support" class="flex items-center justify-center gap-1.5 rounded-full bg-emerald-600 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"',
        ] as $button) {
            $this->assertTrue(str_contains($html, $button), 'a support button lost its solid fill');
        }

        // The pale tints are what dark mode remaps to the card's own grey.
        $this->assertSame(0, preg_match('/global support" class="[^"]*\bbg-(blue|emerald)-50\b/', $html), 'a support button went back to a pale tint');
    }

    public function test_the_nav_search_field_has_a_white_edge_in_dark_mode(): void
    {
        $nav = File::get(resource_path('views/components/nav/main-nav.blade.php'));

        // Desktop and phone fields, at rest.
        $this->assertTrue(str_contains($nav, "'border-[#e4e4e7] hover:border-zinc-300 dark:border-white dark:hover:border-white'"), 'the desktop search field edge is not white in dark mode');
        $this->assertTrue(str_contains($nav, "'border-[#e4e4e7] bg-[#f4f6f5] dark:border-white dark:bg-white/8'"), 'the phone search field edge is not white in dark mode');

        // dark:border-white alone is not enough: the stylesheet's
        // `.dark .border-zinc-200` outranks it and painted the edge #3a3a3a,
        // so the light edge must be written as a colour that rule never names.
        $css = File::get(resource_path('css/app.css'));
        $this->assertTrue(str_contains($css, '.dark .border-zinc-200,'), 'the dark border remap moved; recheck what can outrank the search edge');
        $this->assertSame(0, preg_match("/'border-zinc-200 [^']*dark:border-white/", $nav), 'a search field went back to a light edge that dark mode remaps to grey');
    }
}
