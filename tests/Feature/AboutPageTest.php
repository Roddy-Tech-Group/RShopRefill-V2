<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_about_page_renders_for_guests(): void
    {
        $this->withoutVite();

        $this->get(route('shop.about'))
            ->assertOk()
            ->assertSee('The Global Digital Ecosystem')
            // The story heading now renders the brand from the site.name setting
            // (seeded "RshopRefills") instead of the old hardcoded "RShopRefill".
            ->assertSee('About RshopRefills')
            ->assertSee('Powered by innovation.')
            ->assertSee('Countries');
    }
}
