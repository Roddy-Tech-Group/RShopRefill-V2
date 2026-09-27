<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Footer "Get the app" badges: removed, and this file is what keeps them removed.
 *
 * The two pills used to sit under the footer logo, announcing App Store and
 * Google Play as "Coming soon on", each one routed to the Web App page because
 * neither store link existed to point at. There is no app - none submitted, none
 * in review, no listing waiting on a date - so the badges advertised a download
 * that could not be delivered, and the "coming soon" wording did not fix that,
 * it only put a promise on it. The redesign took them out of the footer rather
 * than restyling them.
 *
 * This file was kept, and inverted, instead of deleted. The removal is the kind
 * of decision that gets undone by accident: the translation keys are still in
 * the footer language files, store badge artwork is a familiar thing to drop
 * into a footer, and nothing else in the codebase would object. Asserting the
 * absence means re-adding the badges fails here, and whoever re-adds them has to
 * come and rewrite this comment deliberately rather than never learning the
 * reason they went.
 *
 * Only the footer pills went. The Web App page they pointed at is untouched and
 * still reachable from the home hero, so the destination survived the promise.
 */
class FooterAppBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_no_longer_advertises_app_store_badges(): void
    {
        $footer = file_get_contents(resource_path('views/components/footer.blade.php'));

        // The source first: the badges are gone from the markup, not merely
        // hidden behind a class or a feature flag that could be flipped back on.
        $this->assertStringNotContainsString('App Store', $footer);
        $this->assertStringNotContainsString('Google Play', $footer);

        // The three translation keys the pills used still exist in the language
        // files, so absence of the copy is not proof on its own. The footer must
        // not read any of them.
        $this->assertStringNotContainsString('footer.get_the_app', $footer);
        $this->assertStringNotContainsString('footer.coming_soon_on', $footer);
        $this->assertStringNotContainsString('footer.app_coming_soon', $footer);

        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Get the app', $html);
        $this->assertStringNotContainsString('App Store', $html);
        $this->assertStringNotContainsString('Google Play', $html);
        $this->assertStringNotContainsString('Coming soon on', $html);
        $this->assertStringNotContainsString('Coming soon - use our Web App today', $html);

        // What the badges linked to is still here. Removing them took away the
        // claim about native apps, not the installable Web App itself, which the
        // home hero still offers and which still answers on its own route.
        $this->assertStringContainsString('href="'.route('shop.mobile-app').'"', $html);
        $this->withoutVite()->get(route('shop.mobile-app'))->assertOk();
    }
}
