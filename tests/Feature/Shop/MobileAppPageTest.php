<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Web App page leads with the installable PWA. The old "coming soon" app-store
 * framing (badge, store buttons, "on the way" copy) has been removed; the page still
 * renders its heading and the PWA install guide.
 */
class MobileAppPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_app_page_leads_with_the_installable_pwa(): void
    {
        $this->withoutVite();

        $this->get('/mobile-app')
            ->assertOk()
            ->assertSee('Web App live')
            ->assertSee('Web App')
            // Install guide: 3 iPhone steps + an Android install card.
            ->assertSee('How to install')
            ->assertSee('Step 1')
            ->assertSee('Step 3')
            ->assertSee('On Android')
            // Coming-soon / app-store framing is gone.
            ->assertDontSee('Our mobile app is on the way')
            ->assertDontSee('Coming soon');
    }
}
