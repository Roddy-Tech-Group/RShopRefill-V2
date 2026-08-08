<?php

namespace Tests\Feature;

use App\Domain\Communication\Enums\MailDomain;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Contact page ignored most of the contact.* settings it was supposed to
 * render. contact.address was seeded with the description "Shown on the contact
 * page when set" but nothing read it, so setting a physical address did
 * nothing; the support email was a literal that had drifted from
 * contact.email_support; and there was no way to publish a second phone number.
 */
class ContactPageDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function set(string $key, string $value): void
    {
        SiteSetting::put($key, $value, 'contact');
    }

    // ────────────────────────────────────────────────────────────
    //  Physical address
    // ────────────────────────────────────────────────────────────

    public function test_the_address_card_renders_every_line_that_was_set(): void
    {
        $this->set('contact.address', "Rue 1.234, Bonapriso\nDouala, Littoral\nCameroon");
        SiteSetting::put('site.legal_name', 'Roddy Technologies LTD', 'site');

        $response = $this->get(route('shop.contact'))->assertOk();

        $response->assertSee('Our address');
        $response->assertSee('Roddy Technologies LTD');
        $response->assertSee('Rue 1.234, Bonapriso');
        $response->assertSee('Douala, Littoral');
        $response->assertSee('Cameroon');
    }

    public function test_the_address_card_is_hidden_when_no_address_is_set(): void
    {
        $this->set('contact.address', '');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertDontSee('Our address');
    }

    public function test_a_maps_link_is_added_only_when_configured(): void
    {
        $this->set('contact.address', 'Douala, Cameroon');
        $this->set('contact.address_maps_url', '');

        $this->get(route('shop.contact'))->assertOk()->assertDontSee('View on map');

        $this->set('contact.address_maps_url', 'https://maps.example.test/rshop');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee('View on map')
            ->assertSee('https://maps.example.test/rshop', escape: false);
    }

    /**
     * contact.address held an email address in production. Rendering that where
     * a street address belongs would have been worse than showing nothing, so
     * the migration clears it and the card stays hidden until it is real.
     */
    public function test_the_migration_cleared_an_email_sitting_in_the_address_field(): void
    {
        $this->assertNotSame(
            'info@rshoprefill.com',
            trim((string) SiteSetting::get('contact.address', '')),
            'An email address must not survive in the physical address setting.'
        );
    }

    // ────────────────────────────────────────────────────────────
    //  Phone numbers
    // ────────────────────────────────────────────────────────────

    public function test_the_second_phone_number_shows_with_its_label(): void
    {
        $this->set('contact.phone_primary', '+1 (940) 238-6229');
        $this->set('contact.phone_secondary', '+237 6 77 00 11 22');
        $this->set('contact.phone_secondary_label', 'Cameroon office');

        $response = $this->get(route('shop.contact'))->assertOk();

        $response->assertSee('+1 (940) 238-6229');
        $response->assertSee('+237 6 77 00 11 22');
        $response->assertSee('Cameroon office');
        // Dialable form strips the formatting.
        $response->assertSee('tel:+237677001122', escape: false);
    }

    public function test_the_second_number_falls_back_to_a_generic_label(): void
    {
        $this->set('contact.phone_secondary', '+237 6 77 00 11 22');
        $this->set('contact.phone_secondary_label', '');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee('Alternate line');
    }

    public function test_no_second_number_renders_when_none_is_set(): void
    {
        $this->set('contact.phone_secondary', '');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertDontSee('Alternate line');
    }

    // ────────────────────────────────────────────────────────────
    //  Support email + hours now follow their settings
    // ────────────────────────────────────────────────────────────

    public function test_the_support_email_follows_the_setting_instead_of_a_literal(): void
    {
        $this->set('contact.email_support', 'help@example.test');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee('help@example.test');
    }

    public function test_the_support_email_falls_back_to_the_platform_reply_to(): void
    {
        $this->set('contact.email_support', '');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee(MailDomain::replyTo());
    }

    public function test_support_hours_follow_the_setting(): void
    {
        $this->set('contact.support_hours', 'Mon to Fri, 9-6 WAT');

        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee('Available Mon to Fri, 9-6 WAT');
    }

    // ────────────────────────────────────────────────────────────
    //  Sender mailbox reference
    // ────────────────────────────────────────────────────────────

    public function test_only_support_and_compliance_are_published(): void
    {
        $this->set('contact.email_support', 'support@rshoprefill.com');

        $response = $this->get(route('shop.contact'))->assertOk();

        $response->assertSee('Emails you receive from us');
        $response->assertSee('support@rshoprefill.com');
        $response->assertSee(MailDomain::Compliance->senderAddress());
        $response->assertSee(MailDomain::Compliance->customerDescription());
    }

    /**
     * Listing all eight senders made the page a wall of addresses and pointed
     * customers at boxes nobody reads. The other six must stay unpublished.
     */
    public function test_the_remaining_sender_mailboxes_are_not_published(): void
    {
        $response = $this->get(route('shop.contact'))->assertOk();

        $hidden = array_filter(
            MailDomain::cases(),
            fn (MailDomain $mailbox) => $mailbox !== MailDomain::Compliance,
        );

        foreach ($hidden as $mailbox) {
            $response->assertDontSee($mailbox->senderAddress());
        }
    }

    /**
     * The department tiles (partnerships, suppliers, press, legal, abuse) all
     * resolved to the same info@ address and were removed with the trim.
     */
    public function test_the_department_tiles_are_gone(): void
    {
        $this->set('contact.email_partnerships', 'partners@example.test');
        $this->set('contact.email_press', 'press@example.test');

        $response = $this->get(route('shop.contact'))->assertOk();

        $response->assertDontSee('Reach out');
        $response->assertDontSee('partners@example.test');
        $response->assertDontSee('press@example.test');
    }

    /**
     * The section is a phishing reference, so it must still say where replies
     * land and must never promise we would ask for a credential by email.
     */
    public function test_the_section_states_where_replies_land(): void
    {
        $this->get(route('shop.contact'))
            ->assertOk()
            ->assertSee('reaches our support team')
            ->assertSee('never ask for your password', escape: false);
    }
}
