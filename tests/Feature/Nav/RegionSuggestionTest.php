<?php

namespace Tests\Feature\Nav;

use App\Support\RegionSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The storefront catalogue is region-locked and defaults to US, so a visitor
 * from anywhere else lands on a catalogue that is not theirs and has no reason
 * to know another one exists. The suggestion popup is the prompt.
 *
 * Two things are worth holding still here, and they are why this file exists:
 *
 *   DETECTION FAILS CLOSED. Cloudflare's CF-IPCountry is the only input, and
 *   it carries sentinels for the cases where the edge itself does not know.
 *   Every one of them, and every malformed or unsupported code, must read as
 *   "no answer" and show nothing. Guessing a country here would send someone
 *   to the wrong catalogue with an air of confidence.
 *
 *   THE POPUP DOES NOT APPLY A REGION. It hands off to the locale modal, which
 *   owns country, language and currency together and is the only place any of
 *   them is saved. A second way to set a region is a second thing to keep in
 *   step, and the two would drift.
 */
class RegionSuggestionTest extends TestCase
{
    use RefreshDatabase;

    /** A country the storefront has a region for, and is not the US default. */
    private const DETECTED = 'CM';

    private function popup(): string
    {
        return File::get(resource_path('views/components/nav/region-suggestion.blade.php'));
    }

    // ---------------------------------------------------------------- detect

    /**
     * Absent header is the normal case off Cloudflare: local development, a
     * direct origin hit, a health check. None of them is a country.
     */
    public function test_no_header_is_not_a_country(): void
    {
        $this->assertNull(RegionSuggestion::detect(request()));
    }

    /**
     * XX is "could not determine", T1 is Tor, and EU / AP are groupings sent
     * for ranges that span countries. All four are answers about the lookup,
     * not about the visitor.
     */
    public function test_cloudflare_sentinels_are_not_countries(): void
    {
        foreach (['XX', 'T1', 'EU', 'AP'] as $sentinel) {
            $this->assertNull(
                RegionSuggestion::detect($this->requestWithCountry($sentinel)),
                $sentinel.' is not a country.'
            );
        }
    }

    /** Anything that is not two letters is malformed, whatever it means. */
    public function test_malformed_codes_are_rejected(): void
    {
        foreach (['C', 'CM1', '12', 'c-m', ' ', 'ZZZ'] as $junk) {
            $this->assertNull(RegionSuggestion::detect($this->requestWithCountry($junk)), $junk.' is malformed.');
        }
    }

    /**
     * ZZ is well-formed and reserved, so it passes the shape check and has to
     * be stopped by the catalogue lookup instead. That is the assertion: the
     * supported list is a real gate, not decoration behind the regex.
     */
    public function test_a_wellformed_but_unsupported_code_is_rejected(): void
    {
        $this->assertFalse(RegionSuggestion::isSupported('ZZ'));
        $this->assertNull(RegionSuggestion::detect($this->requestWithCountry('ZZ')));
    }

    /** A supported code survives, in either case. */
    public function test_a_supported_country_is_detected(): void
    {
        $this->assertSame('CM', RegionSuggestion::detect($this->requestWithCountry('CM')));
        $this->assertSame('CM', RegionSuggestion::detect($this->requestWithCountry('cm')));
    }

    // -------------------------------------------------------------- forRegion

    /** Nothing to say to someone already on their own region. */
    public function test_no_suggestion_when_the_visitor_is_already_home(): void
    {
        $this->assertNull(RegionSuggestion::forRegion('CM', $this->requestWithCountry('CM')));
    }

    /** The sentence needs both names, so both come back with the code. */
    public function test_a_suggestion_names_both_regions(): void
    {
        $suggestion = RegionSuggestion::forRegion('US', $this->requestWithCountry('CM'));

        $this->assertSame('CM', $suggestion['iso']);
        $this->assertSame('Cameroon', $suggestion['name']);
        $this->assertSame('United States', $suggestion['current']);
    }

    /**
     * An active region we cannot name would render "products redeemable in ."
     * Better to say nothing than to say that.
     */
    public function test_an_unnameable_active_region_suppresses_the_suggestion(): void
    {
        $this->assertNull(RegionSuggestion::forRegion('ZZ', $this->requestWithCountry('CM')));
    }

    // ----------------------------------------------------------------- render

    /** Off Cloudflare there is no header, so the markup is simply absent. */
    public function test_the_popup_does_not_render_without_detection(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="region-suggest-title"', $html);
    }

    public function test_the_popup_renders_for_a_detected_visitor_away_from_home(): void
    {
        $html = $this->withoutVite()
            ->withHeaders(['CF-IPCountry' => self::DETECTED])
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="region-suggest-title"', $html);
        $this->assertStringContainsString('Cameroon', $html);
    }

    /**
     * Once, not twice. x-nav.utility-links is mounted twice by design - the
     * phone menu sheet and the desktop bar - so the flag that pulls the popup
     * in rides only the desktop mount. Two copies would mean two popups, and
     * dismissing one would leave the other.
     */
    public function test_the_popup_renders_exactly_once(): void
    {
        $html = $this->withoutVite()
            ->withHeaders(['CF-IPCountry' => self::DETECTED])
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="region-suggest-title"'));
    }

    /** Nothing to suggest to a visitor whose region already matches. */
    public function test_the_popup_is_absent_when_the_region_already_matches(): void
    {
        $html = $this->withoutVite()
            ->withHeaders(['CF-IPCountry' => self::DETECTED])
            ->get('/?country='.self::DETECTED)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="region-suggest-title"', $html);
    }

    /**
     * A dismissal is honoured on the SERVER. Rendering it and hiding it on boot
     * would still have sent it to someone who already said no, and a slow boot
     * would flash it at them.
     *
     * This is also the guard on the cookie's encryption exemption. The popup
     * writes the cookie from JavaScript, so it arrives in plain text - which is
     * what withUnencryptedCookie sends here. Drop the exemption in
     * bootstrap/app.php and the framework decrypts it to null, the server-side
     * check silently stops working, and this test is what says so.
     */
    public function test_a_dismissed_popup_never_reaches_the_browser(): void
    {
        $html = $this->withoutVite()
            ->withHeaders(['CF-IPCountry' => self::DETECTED])
            ->withUnencryptedCookie('region_suggest_dismissed', '1')
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('id="region-suggest-title"', $html);
    }

    // ------------------------------------------------------------- behaviour

    /** Every exit is a dismissal: close, Escape and clicking away all remember. */
    public function test_every_exit_remembers_the_dismissal(): void
    {
        $popup = $this->popup();

        $this->assertStringContainsString('x-on:keydown.escape.window="if (open) close()"', $popup);
        $this->assertStringContainsString('x-on:click.outside="if (open) close()"', $popup);
        $this->assertStringContainsString('this.remember();', $popup);
        $this->assertStringContainsString('max-age=\' + (60 * 60 * 24 * 30)', $popup);
    }

    /**
     * It arrives after the page has settled rather than competing with it.
     * Instant would land it mid-paint, over content the visitor has not read.
     */
    public function test_it_waits_before_appearing(): void
    {
        $this->assertStringContainsString('}, 800);', $this->popup());
    }

    /**
     * The handoff. The popup dispatches the event the locale modal already
     * listens for, carrying the suggested country; it never writes a region
     * cookie or posts locale.update itself.
     */
    public function test_switching_opens_the_existing_modal_instead_of_applying_a_region(): void
    {
        $popup = $this->popup();

        $this->assertStringContainsString("new CustomEvent('open-locale-modal'", $popup);
        $this->assertStringContainsString('detail: { country:', $popup);

        // No second way to set a region. The only cookie it writes is its own
        // dismissal; the `region` cookie belongs to ResolveRegion, which sets
        // it from the ?country= the modal navigates with.
        $this->assertStringNotContainsString('locale.update', $popup);
        $this->assertStringNotContainsString("document.cookie = 'region=", $popup);
        $this->assertStringContainsString("document.cookie = 'region_suggest_dismissed=", $popup);
    }

    /**
     * One modal, one listener. The storefront used to open the sheet by
     * assigning localeModalOpen through Alpine's scope chain, so the event
     * existed but only the dashboard listened for it. The listener lives on the
     * modal now, which is also the only scope that can stage a country - a
     * parent cannot call a child scope's method.
     */
    public function test_the_modal_owns_the_only_listener(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));
        $header = File::get(resource_path('views/components/layouts/app/header.blade.php'));

        $this->assertStringContainsString('@open-locale-modal.window', $modal);
        $this->assertStringContainsString('suggestCountry(', $modal);
        $this->assertStringNotContainsString('@open-locale-modal.window', $header);
    }

    /**
     * Staging, not saving - the same rule every other pane in the sheet
     * follows. The suggested country fills the draft and waits for Save.
     */
    public function test_the_suggested_country_is_staged_rather_than_saved(): void
    {
        $modal = File::get(resource_path('views/components/nav/locale-modal.blade.php'));

        $this->assertStringContainsString('this.draftCountry =', $modal);
        $this->assertStringNotContainsString('this.country =', $modal);
    }

    /**
     * Absolutely positioned under a relatively positioned anchor, so appearing
     * moves nothing. A popup that reflowed the header would shift the page
     * under a cursor 800ms after load.
     */
    public function test_it_cannot_shift_the_layout(): void
    {
        $this->assertStringContainsString('absolute right-0 top-full', $this->popup());
        $this->assertStringContainsString(
            'relative flex shrink-0 items-center',
            File::get(resource_path('views/components/nav/region-pill.blade.php'))
        );
    }

    /** Narrow enough for a phone, capped on a desktop. */
    public function test_it_fits_a_phone(): void
    {
        $this->assertStringContainsString('w-[min(92vw,420px)]', $this->popup());
    }

    private function requestWithCountry(string $code): Request
    {
        return Request::create('/', 'GET', [], [], [], [
            'HTTP_CF_IPCOUNTRY' => $code,
        ]);
    }
}
