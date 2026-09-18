<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompliancePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_compliance_page_renders_for_guests(): void
    {
        $this->withoutVite();

        $this->get(route('shop.compliance'))
            ->assertOk()
            ->assertSee('Compliance and Regulatory Framework')
            ->assertSee('zero tolerance')
            ->assertSee('Know Your Customer')
            ->assertSee('Sanctions and geographic restrictions')
            ->assertSee('support@rshoprefill.com')
            ->assertDontSee('info@rshoprefill.com');
    }
}
