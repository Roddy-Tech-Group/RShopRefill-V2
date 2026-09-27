<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A bottom sheet can be pushed back down.
 *
 * A sheet that slides up from the bottom of a phone is asking to be swiped
 * away, and every one of ours could only be dismissed by finding the close
 * button or the strip of backdrop above it. Each already drew a handle at the
 * top, which said "drag me" and meant nothing.
 *
 * One helper, loaded beside the scroll lock for the same reason: every sheet
 * on the site reaches for it, so it has to exist before any of them boots.
 */
class SheetSwipeToCloseTest extends TestCase
{
    private function helper(): string
    {
        return File::get(resource_path('views/partials/sheet-swipe.blade.php'));
    }

    public function test_the_helper_is_loaded_before_any_sheet_boots(): void
    {
        $head = File::get(resource_path('views/partials/head.blade.php'));

        $this->assertStringContainsString("@include('partials.sheet-swipe')", $head);
        // Beside the scroll lock, which every sheet also reaches for.
        $this->assertLessThan(
            strpos($head, "@include('partials.sheet-swipe')"),
            strpos($head, "@include('partials.scroll-lock')"),
        );
    }

    /**
     * The gesture only means "close" when the sheet is scrolled to its top.
     * Inside a scrolled list the same drag is the customer reading, and taking
     * it would leave the sheet impossible to scroll back up through.
     */
    public function test_a_scrolled_sheet_keeps_its_own_scrolling(): void
    {
        $js = $this->helper();

        $this->assertStringContainsString('const atTop = () => el.scrollTop <= 0;', $js);
        $this->assertStringContainsString('if (ev.touches.length !== 1 || ! atTop() || fromControl(ev.target))', $js);

        // Dragging upward is the sheet's scroll, so the helper hands it back.
        $this->assertStringContainsString('if (delta <= 0) {', $js);
    }

    /** A drag that starts on a control belongs to that control. */
    public function test_controls_inside_the_sheet_keep_their_own_gestures(): void
    {
        $this->assertStringContainsString(
            'node.closest(\'button, a, input, select, textarea, [role="button"], [data-no-sheet-swipe]\')',
            $this->helper()
        );
    }

    /** A short push snaps back; a long one or a flick closes. */
    public function test_it_takes_a_real_push_or_a_flick_to_close(): void
    {
        $js = $this->helper();

        $this->assertStringContainsString('const far = travelled > Math.max(CLOSE_PX, el.offsetHeight * CLOSE_FRACTION);', $js);
        $this->assertStringContainsString('if (far || (travelled > 24 && velocity > FLICK_VELOCITY)) {', $js);

        // The distance is a share of the sheet's own height, so a tall sheet
        // asks for a longer push than a short one.
        $this->assertMatchesRegularExpression('/CLOSE_FRACTION = 0\.\d+;/', $js);
    }

    /**
     * Closing goes through whatever the sheet already does to close, so a
     * sheet that also releases a scroll lock or clears a selection keeps
     * doing it by the same path as its close button.
     */
    public function test_closing_is_the_sheet_s_own_business(): void
    {
        $js = $this->helper();

        $this->assertStringContainsString('window.rshopSheetSwipe = function (el, close) {', $js);
        $this->assertStringContainsString('close();', $js);

        // And the inline transform is always handed back, so the sheet's own
        // leave transition runs from wherever the finger left it.
        $this->assertStringContainsString("el.style.transform = '';", $js);
    }

    /** Wiring it twice on one element would double every handler. */
    public function test_a_sheet_is_only_wired_once(): void
    {
        $js = $this->helper();

        $this->assertStringContainsString("el.dataset.sheetSwipe === 'on'", $js);
        $this->assertStringContainsString("el.dataset.sheetSwipe = 'on';", $js);
    }

    /** Every sheet a phone actually meets is wired up. */
    public function test_the_sheets_are_wired(): void
    {
        $sheets = [
            'views/dashboard.blade.php' => ['open'],
            'views/shop/_amount-fields.blade.php' => ['denomSheet'],
            'views/shop/esim.blade.php' => ['showBuy', 'showNetworks', 'showCompat', 'showDetails'],
        ];

        foreach ($sheets as $view => $flags) {
            $source = File::get(resource_path($view));

            foreach ($flags as $flag) {
                $this->assertStringContainsString(
                    'window.rshopSheetSwipe?.($el, () => '.$flag.' = false)',
                    $source,
                    $view.' does not let '.$flag.' be swiped away'
                );
            }
        }
    }
}
