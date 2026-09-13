<?php

namespace Tests\Feature\Nav;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UtilityLinksLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The cluster is two files: the links, and the region pill it pulls in.
     * The pill was extracted so the desktop cluster, the phone nav row and the
     * menu sheet share one control rather than three copies of it.
     */
    private function cluster(): string
    {
        return File::get(resource_path('views/components/nav/utility-links.blade.php'))
            .File::get(resource_path('views/components/nav/region-pill.blade.php'));
    }

    public function test_region_and_currency_are_one_control(): void
    {
        // They were two buttons - country and language - opening the SAME modal,
        // so the split bought nothing and cost width.
        $bar = $this->cluster();

        $this->assertSame(1, substr_count($bar, 'localeModalOpen = true'));
        $this->assertStringContainsString("x-text=\"currency || 'USD'\"", $bar);
        $this->assertStringNotContainsString('x-text="language"', $bar);
        $this->assertStringNotContainsString('x-text="country"', $bar);
    }

    public function test_the_pill_still_names_everything_for_screen_readers(): void
    {
        // The visible label collapses to "EN / USD"; the accessible name must
        // not, or the control stops saying what it changes.
        $bar = $this->cluster();

        $this->assertStringContainsString(':aria-label=', $bar);
        foreach (["' + country + '", "' + currency + '"] as $part) {
            $this->assertStringContainsString($part, $bar);
        }

        // The language half names the translate engine's chosen language.
        // It cannot come from the server locale: the engine translates into
        // languages the app ships no strings for, so the pill read "EN" over
        // a fully Spanish page.
        $this->assertStringContainsString("language || 'English'", $bar);
        $this->assertStringContainsString("' + (language || 'English') + '", $bar);
    }

    public function test_it_renders_with_a_sane_default_before_alpine_boots(): void
    {
        // x-text is empty until Alpine hydrates, so the server-rendered
        // fallback is what a no-JS or slow client sees. The two halves sit in
        // their own spans now, so this checks the fallback content rather
        // than the joined-up label.
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('>EN</span>', $html);
        $this->assertStringContainsString('>USD</span>', $html);
    }

    /**
     * The pill tracks the translate engine's language, which is client
     * state - so the server cannot render it, and must not pretend to. It
     * ships the neutral fallback and Alpine fills in the real choice.
     */
    public function test_the_pill_reads_the_engines_language_not_the_server_locale(): void
    {
        $pill = File::get(resource_path('views/components/nav/region-pill.blade.php'));

        $this->assertStringContainsString("x-text=\"(language || 'English')", $pill);
        $this->assertStringNotContainsString("{{ config('localization.supported.'.app()->getLocale().'.short') }} /", $pill);
    }

    /**
     * `locale.language` is the translate engine's live choice, so the nav
     * reads it and nothing deletes it. A removal shim here - correct while
     * the engine was gone - wiped the language on every page load once it
     * came back, leaving only the cookie to keep the page translated while
     * the nav fell back to "English".
     */
    public function test_the_language_key_is_read_and_never_wiped(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("read('language'", $js);
        $this->assertStringNotContainsString("localStorage.removeItem('locale.language')", $js);
    }

    /**
     * The locale modal is a paned sheet: a root list of the three settings, and
     * a chooser for each. Panes rather than inline dropdowns because a ~200-row
     * country list has nowhere to go inside a centred modal.
     */
    public function test_the_modal_is_a_paned_preferences_sheet(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        foreach (['Preferences', 'Country', 'Payment currency'] as $label) {
            $this->assertStringContainsString($label, $modal);
        }

        foreach (['country', 'language', 'currency'] as $pane) {
            $this->assertStringContainsString('pane = \''.$pane.'\'', $modal, $pane.' should be reachable.');
            $this->assertStringContainsString('x-show="pane === \''.$pane.'\'"', $modal);
        }

        // Closing resets the pane AND throws the draft away, or the sheet
        // reopens mid-chooser showing a choice that was never saved.
        $this->assertStringContainsString(
            "x-effect=\"if (! localeModalOpen) { pane = 'root'; syncDrafts(); }\"",
            $modal
        );

        // Escape steps back out of a chooser before it closes the sheet.
        $this->assertStringContainsString("pane === 'root' ? localeModalOpen = false : pane = 'root'", $modal);
    }

    /**
     * Page scroll is frozen while the sheet is open. It is the layout that holds
     * the lock, not the modal - the same reference-counted lock every other
     * overlay uses, so nesting one inside another cannot unlock early.
     */
    public function test_the_open_modal_freezes_the_page(): void
    {
        $header = File::get(resource_path('views/components/layouts/app/header.blade.php'));

        $this->assertStringContainsString(
            'x-effect="localeModalOpen ? window.rshopScrollLock?.lock() : window.rshopScrollLock?.unlock()"',
            $header
        );

        $lock = File::get(resource_path('views/partials/scroll-lock.blade.php'));
        $this->assertStringContainsString('let counter = 0;', $lock);
    }

    /**
     * A chooser slides in from the right, and its list shows six rows and
     * scrolls the rest rather than growing the card.
     */
    public function test_a_chooser_slides_in_and_scrolls_inside_the_card(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        // One slide per chooser - enter and leave, so six mentions in total.
        $this->assertSame(6, substr_count($modal, 'translate-x-full'));

        // Clipped by the card, or the off-screen start widens the sheet.
        $this->assertStringContainsString('flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden', $modal);

        // Every pane sits in the SAME grid cell - root plus the three choosers.
        // As flex siblings, both panes had height for one frame during a
        // transition, so Back made the card lunge taller and snap back.
        $this->assertSame(4, substr_count($modal, 'col-start-1 row-start-1'));
        $this->assertStringNotContainsString('class="flex min-h-0 flex-1 flex-col"', $modal);

        // Six rows: 6 x 3rem plus the card's 0.5rem padding. NOT flex-1 - a
        // list narrowed to two matches should be two rows tall, not a six-row
        // box with a hole in it.
        //
        // Two panels in the modal (country, currency), not three: the language
        // pane mounts x-nav.language-switcher, which carries its own panel. The
        // modal used to wrap that mount in an identical panel of its own, so
        // the list drew a second ring and two 18.5rem scroll areas nested.
        $this->assertSame(2, substr_count($modal, 'min-h-0 max-h-[18.5rem] overflow-y-auto'));
        $switcher = File::get(resource_path('views/components/nav/language-switcher.blade.php'));
        $this->assertSame(1, substr_count($switcher, 'min-h-0 max-h-[18.5rem] overflow-y-auto'));
        $this->assertStringNotContainsString('min-h-0 flex-1 overflow-y-auto', $modal);

        // A row is 3rem in every list, so "six rows" is one height: py-3 either
        // side of a 24px mark. The currency chip was 28px and broke that.
        $this->assertStringNotContainsString('h-7 w-7', $modal);
    }

    /**
     * Choosing stages; Save commits. Picking used to write the live locale
     * scope, whose watchers persisted and navigated - so every mis-tap was a
     * page reload and Save was decoration.
     */
    public function test_choosing_only_stages_until_save_is_pressed(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));
        $switcher = File::get(resource_path('views/components/nav/language-switcher.blade.php'));

        // Every pane writes a draft.
        foreach ([
            'this.draftCountry = name;',
            'draftCurrency = c.code;',
        ] as $write) {
            $this->assertStringContainsString($write, $modal);
        }
        // The language rows stage a NAME now - the engine's list, not the
        // app's locale codes.
        $this->assertStringContainsString('draftLanguage = name', $switcher);

        // And nothing writes the live scope from a pane. `this.country =` is
        // the assignment that used to apply a country the moment it was tapped.
        foreach (['this.country =', 'this.countryCode =', 'this.currencySymbol ='] as $live) {
            $this->assertStringNotContainsString($live, $modal);
        }

        // The root list previews the draft, not what is in force.
        $this->assertStringContainsString('x-text="draftCountry"', $modal);
        $this->assertStringContainsString('x-text="draftCurrency"', $modal);
        $this->assertStringContainsString('x-text="draftLanguage"', $modal);
    }

    /**
     * Save is a real form POST, so the language half needs no JavaScript beyond
     * the modal itself - and one submit applies all three preferences with a
     * single page load instead of racing a watcher-driven navigation.
     */
    public function test_save_submits_one_csrf_protected_post(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        $this->assertStringContainsString('action="{{ route(\'locale.update\') }}"', $modal);
        $this->assertStringContainsString('@csrf', $modal);
        $this->assertStringContainsString('<input type="hidden" name="locale" value="{{ app()->getLocale() }}">', $modal);

        // The language rides along as the engine's cookie, set in the same
        // commit so the POST's redirect is the one reload that applies it.
        $this->assertStringContainsString('window.rshopSetTranslateCookie', $modal);
        $this->assertStringContainsString('type="submit"', $modal);
        $this->assertStringContainsString('@submit="commit($event)"', $modal);

        // Country and currency go to localStorage directly. Assigning them to
        // the shared scope would fire storefrontLocale's watchers, which
        // schedule their own navigation and race this POST.
        $this->assertStringContainsString("localStorage.setItem('locale.countryCode'", $modal);
        $this->assertStringContainsString('window.history.replaceState', $modal);

        // Enter inside a chooser's search box must not submit the sheet.
        $this->assertSame(2, substr_count($modal, '@keydown.enter.prevent'));

        // An unchanged sheet costs no page load.
        $this->assertStringContainsString('if (! this.dirty) {', $modal);
    }

    /** The rendered page carries the form, token and hidden locale field. */
    public function test_the_save_form_reaches_the_browser(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('locale.update').'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="locale"', $html);

        // No nested forms: the sheet is one form, and the language rows are
        // buttons inside it rather than a form each.
        $this->assertSame(1, substr_count($html, 'action="'.route('locale.update').'"'));
    }
}
