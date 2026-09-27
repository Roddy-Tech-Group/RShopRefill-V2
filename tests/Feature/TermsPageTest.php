<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_terms_page_renders_for_guests(): void
    {
        $this->withoutVite();

        $this->get(route('shop.terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Acceptance of these Terms')
            ->assertSee('Acceptable use')
            ->assertSee('support@rshoprefill.com')
            ->assertDontSee('info@rshoprefill.com');
    }
}
