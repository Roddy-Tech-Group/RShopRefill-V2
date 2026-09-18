<?php

namespace Tests\Feature\Shop;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Request a product": a customer asking us to stock a brand or service we do
 * not carry. Shares _inquiry-form with the influencers page and lands in the
 * same Support Tickets list, tagged so it can be filtered out.
 */
class RequestProductPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_with_its_own_labels(): void
    {
        $this->withoutVite()->get(route('shop.request-product'))
            ->assertOk()
            ->assertSee('Request a product')
            ->assertSee('Product or brand')
            ->assertSee('Country or region')
            // The steps and checklist the shared form grew for this page.
            ->assertSee('How it works')
            ->assertSee('Free to ask');
    }

    public function test_a_request_lands_as_a_tagged_contact_message(): void
    {
        $this->post(route('request-product.send'), [
            'name' => 'Divine Ofeh',
            'email' => 'divine@example.com',
            'company' => 'Nintendo eShop',
            'company_url' => 'https://example.com/nintendo',
            'role' => 'Cameroon',
            'category' => 'Gift card',
            'message' => 'I would like to buy Nintendo eShop credit for my console.',
        ])->assertRedirect(route('shop.request-product'));

        $contact = ContactMessage::latest('id')->first();

        $this->assertNotNull($contact);
        $this->assertSame('divine@example.com', $contact->email);
        // Tagged so product requests are filterable in Support Tickets.
        $this->assertStringStartsWith('Product request', $contact->subject);

        // The body re-labels the shared form's field names for whoever reads
        // the ticket: "company" is a product here, not a company.
        $this->assertStringContainsString('Product or brand: Nintendo eShop', $contact->message);
        $this->assertStringContainsString('Country or region: Cameroon', $contact->message);
        $this->assertStringNotContainsString('Brand, channel or handle', $contact->message);
    }

    public function test_the_honeypot_silently_drops_a_bot(): void
    {
        $this->post(route('request-product.send'), [
            'website_hp' => 'http://spam.example',
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'company' => 'Spam',
            'message' => 'Buy my product, it is very good and you will love it.',
        ]);

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_the_footer_shop_column_links_to_it(): void
    {
        $markup = file_get_contents(resource_path('views/components/footer.blade.php'));

        $this->assertStringContainsString("route('shop.request-product')", $markup);
        $this->assertStringContainsString("__('footer.request_a_product')", $markup);
    }

    public function test_every_locale_carries_the_label(): void
    {
        // The footer ships in ten languages; a key that exists only in English
        // renders the raw "footer.request_a_product" for the other nine.
        foreach (glob(lang_path('*/footer.php')) as $file) {
            $this->assertArrayHasKey(
                'request_a_product',
                require $file,
                basename(dirname($file)).' is missing the request_a_product label.'
            );
        }
    }

    public function test_the_dashboard_partnership_tab_renders_the_same_form(): void
    {
        $markup = file_get_contents(resource_path('views/dashboard/rewards.blade.php'));

        // It used to be a link out to the storefront page. Both surfaces now
        // include one body, so the form cannot drift between them.
        $this->assertStringContainsString("@include('shop._inquiry-form-body'", $markup);
        $this->assertStringNotContainsString('Apply to become a partner', $markup);
    }
}
