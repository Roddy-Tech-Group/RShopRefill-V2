<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_page_renders_for_guests(): void
    {
        $this->withoutVite();

        $this->get(route('shop.privacy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Information we collect')
            ->assertSee('We never sell your personal data to third-party advertisers.')
            ->assertSee('Data Protection Officer')
            ->assertSee('support@rshoprefill.com')
            ->assertDontSee('info@rshoprefill.com');
    }
}
