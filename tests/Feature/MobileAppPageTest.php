<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAppPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_app_page_renders(): void
    {
        $this->withoutVite();

        $this->get(route('shop.mobile-app'))
            ->assertOk()
            ->assertSee('Web App')
            ->assertSee('How to install')
            // The "coming soon" app-store framing is gone.
            ->assertDontSee('Our mobile app is on the way')
            ->assertDontSee('Coming soon');
    }
}
