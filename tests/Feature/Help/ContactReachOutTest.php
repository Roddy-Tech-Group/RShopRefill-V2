<?php

namespace Tests\Feature\Help;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The contact page "Reach out" department emails render 2 columns on mobile and
 * iPad, 3 on desktop (grid-cols-2 lg:grid-cols-3).
 */
class ContactReachOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_reach_out_emails_render_in_two_columns(): void
    {
        // A department email makes the "Reach out" section render.
        SiteSetting::put('contact.email_partnerships', 'partners@example.com', 'contact');

        $response = $this->get('/contact')->assertOk();

        $response->assertSee('Reach out');
        $response->assertSee('partners@example.com');
        // 2 columns on mobile + iPad, 3 on desktop.
        $response->assertSee('grid-cols-2 gap-x-10 gap-y-8 lg:grid-cols-3', false);
    }
}
