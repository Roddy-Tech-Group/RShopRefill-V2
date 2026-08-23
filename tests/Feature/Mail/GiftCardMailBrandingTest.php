<?php

namespace Tests\Feature\Mail;

use App\Domain\Communication\Mail\Notifications\GiftCardsRestoredMail;
use App\Domain\Communication\Mail\Notifications\GiftCardsUnavailableMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards that the gift-card availability emails render through the shared
 * branded <x-emails.layout> (logo, blue accent, social footer) instead of
 * Laravel's stock markdown mail theme. Regression cover for the CTO's
 * mail/* templates drifting from the rest of resources/views/emails/.
 */
class GiftCardMailBrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Markers only the shared branded shell emits.
     */
    private function assertBranded(string $html): void
    {
        // The shared shell renders the logo (alt = site name), the blue accent
        // bar/buttons, and the marketplace social footer - none of which the
        // stock Laravel markdown mail theme emits.
        $this->assertStringContainsString('alt="RshopRefills"', $html, 'Branded logo header missing');
        $this->assertStringContainsString('#2563eb', $html, 'Brand blue accent missing');
        $this->assertStringContainsString('your digital marketplace for gift cards', $html, 'Branded footer missing');
        // Stock Laravel markdown-mail components must be gone.
        $this->assertStringNotContainsString('x-mail::message', $html);
    }

    public function test_gift_cards_restored_mail_is_branded(): void
    {
        $user = User::factory()->make(['name' => 'Sam Buyer']);

        $html = (new GiftCardsRestoredMail($user))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('Sam Buyer', $html);
        // Trading is removed: the CTA points at the BUY page, never the old
        // trading dashboard.
        $this->assertStringContainsString('Shop gift cards now', $html);
        $this->assertStringContainsString('/dashboard/shop/gift-cards', $html);
    }

    public function test_gift_cards_unavailable_mail_is_branded(): void
    {
        $user = User::factory()->make(['name' => 'Sam Buyer']);

        $html = (new GiftCardsUnavailableMail($user))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('Sam Buyer', $html);
        $this->assertStringContainsString('temporarily unavailable', $html);
    }
}
