<?php

namespace Tests\Feature\Mail;

use App\Enums\TradeStatus;
use App\Mail\AdminNewTradeMail;
use App\Mail\GiftCardsRestored;
use App\Mail\GiftCardsTemporarilyUnavailable;
use App\Mail\TradeStatusMail;
use App\Models\GiftCardBrand;
use App\Models\GiftCardRate;
use App\Models\GiftCardTrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards that the gift-card trade + availability emails render through the
 * shared branded <x-emails.layout> (logo, blue accent, social footer) instead
 * of Laravel's stock markdown mail theme. Regression cover for the CTO's
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

    private function makeTrade(): GiftCardTrade
    {
        $user = User::factory()->make(['name' => 'Jane Doe', 'email' => 'jane@example.test']);

        $rate = new GiftCardRate;
        $rate->setRelation('brand', new GiftCardBrand(['name' => 'Amazon']));

        $trade = new GiftCardTrade([
            'uuid' => 'abcd1234-5678-90ab-cdef-000000000000',
            'declared_value' => 100,
            'payout_currency' => 'USD',
            'calculated_payout' => 90,
        ]);
        $trade->id = 7;
        $trade->status = TradeStatus::Approved;
        $trade->setRelation('user', $user);
        $trade->setRelation('rate', $rate);

        return $trade;
    }

    public function test_trade_status_mail_is_branded(): void
    {
        $html = (new TradeStatusMail($this->makeTrade(), 'Card verified.'))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('abcd1234', $html);
        $this->assertStringContainsString(TradeStatus::Approved->label(), $html);
        $this->assertStringContainsString('Card verified.', $html);
        $this->assertStringContainsString('View trade details', $html);
    }

    public function test_admin_new_trade_mail_is_branded(): void
    {
        $html = (new AdminNewTradeMail($this->makeTrade()))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('New gift card trade submitted', $html);
        $this->assertStringContainsString('jane@example.test', $html);
        $this->assertStringContainsString('Amazon', $html);
        $this->assertStringContainsString('100.00', $html);
        $this->assertStringContainsString('Review trade now', $html);
    }

    public function test_gift_cards_restored_mail_is_branded(): void
    {
        $user = User::factory()->make(['name' => 'Sam Buyer']);

        $html = (new GiftCardsRestored($user))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('Sam Buyer', $html);
        $this->assertStringContainsString('Trade gift cards now', $html);
        $this->assertStringContainsString('/dashboard/gift-cards', $html);
    }

    public function test_gift_cards_unavailable_mail_is_branded(): void
    {
        $user = User::factory()->make(['name' => 'Sam Buyer']);

        $html = (new GiftCardsTemporarilyUnavailable($user))->render();

        $this->assertBranded($html);
        $this->assertStringContainsString('Sam Buyer', $html);
        $this->assertStringContainsString('temporarily unavailable', $html);
    }
}
