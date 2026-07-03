<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mobile-app ("on the way") page no longer shows the development
 * illustration; the page still renders its heading and PWA install card.
 */
class MobileAppPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_illustration_is_removed(): void
    {
        $this->get('/mobile-app')
            ->assertOk()
            ->assertSee('Our mobile app is on the way')
            ->assertDontSee('Development Mood', false)
            // Copy: stores coming, Web App already active.
            ->assertSee('App Store and Play Store')
            ->assertSee('Web App is already')
            // Install guide: 3 iPhone steps + an Android install card.
            ->assertSee('How to install')
            ->assertSee('Step 1')
            ->assertSee('Step 3')
            ->assertSee('On Android');
    }
}
