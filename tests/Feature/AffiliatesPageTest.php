<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Influencers and Affiliates replaced the separate Partnerships and Suppliers
 * pages: one form, one footer link, and the old URLs redirect to it.
 */
class AffiliatesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_page_renders_with_creator_labels(): void
    {
        $this->get(route('shop.affiliates'))
            ->assertOk()
            ->assertSee('Influencers and Affiliates')
            ->assertSee('Brand, channel or handle')
            ->assertSee('Main platform')
            ->assertSee('Audience size')
            ->assertSee('action="'.route('affiliates.send').'"', false)
            ->assertDontSee('Acme Inc.');
    }

    public function test_the_old_partnerships_and_suppliers_urls_redirect_here(): void
    {
        foreach (['/partnerships', '/suppliers'] as $old) {
            $this->get($old)
                ->assertStatus(301)
                ->assertRedirect('/influencers-affiliates');
        }
    }

    public function test_a_creator_can_submit_an_inquiry(): void
    {
        $this->post(route('affiliates.send'), [
            'name' => 'Ada Creator',
            'email' => 'ada@example.test',
            'company' => '@adacreates',
            'company_url' => 'https://example.test/@adacreates',
            'role' => '25,000 followers',
            'category' => 'TikTok',
            'message' => 'I review travel gear and would love to feature your eSIMs.',
        ])
            ->assertRedirect(route('shop.affiliates'))
            ->assertSessionHas('contact_sent', true);

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'ada@example.test',
            'subject' => 'Influencer and affiliate inquiry - TikTok',
        ]);
        $this->assertDatabaseHas('admin_notifications', ['type' => 'contact']);

        $message = ContactMessage::where('email', 'ada@example.test')->value('message');
        $this->assertStringContainsString('Brand, channel or handle: @adacreates', $message);
        $this->assertStringContainsString('Audience size: 25,000 followers', $message);
        $this->assertStringContainsString('Main platform: TikTok', $message);
    }

    public function test_the_footer_carries_one_link_instead_of_two(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('shop.affiliates').'"', false)
            ->assertDontSee('href="'.url('/partnerships').'"', false)
            ->assertDontSee('href="'.url('/suppliers').'"', false);
    }

    public function test_the_xml_sitemap_lists_the_new_page_not_the_redirects(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(route('shop.affiliates'), false)
            ->assertDontSee(url('/partnerships'), false)
            ->assertDontSee(url('/suppliers'), false);
    }
}
