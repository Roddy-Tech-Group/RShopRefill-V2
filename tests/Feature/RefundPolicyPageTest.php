<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_refund_policy_page_renders_for_guests(): void
    {
        $this->withoutVite();

        $this->get(route('shop.refund-policy'))
            ->assertOk()
            ->assertSee('Refund and Cancellation Policy')
            ->assertSee('Global wallet-first refund policy')
            ->assertSee('Automatic refund after a fast system check.')
            ->assertSee('support@rshoprefill.com')
            ->assertDontSee('info@rshoprefill.com');
    }
}
