<?php

namespace Tests\Feature\Notification;

use App\Domain\Communication\Mail\Orders\OrderPlacedMail;
use App\Domain\Communication\Mail\Security\WelcomeMail;
use App\Domain\Communication\Providers\ResendProvider;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Platform emails go out through the Resend HTTP provider. The provider must
 * render the mailable through the real Mailer pipeline so inline CID images
 * (the brand logo) exist, and forward them as attachments carrying a
 * content_id - a plain render() has no $message and ships emails with broken
 * logos.
 */
class EmailChannelInlineImagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.resend.key' => 'test-key']);

        $this->captured = [];

        Http::fake(function ($request) {
            $this->captured = $request->data();

            return Http::response(['id' => 'fake-resend-id'], 200);
        });
    }

    private function sendThroughProvider(object $mailable, string $to): void
    {
        (new ResendProvider)->send(
            to: $to,
            from: 'Orders <orders@rshoprefill.com>',
            replyTo: 'support@rshoprefill.com',
            subject: 'Test subject',
            mailable: $mailable,
        );
    }

    public function test_welcome_email_carries_the_logo_as_an_inline_cid_attachment(): void
    {
        $user = User::factory()->create();

        $this->sendThroughProvider(new WelcomeMail($user), $user->email);

        $this->assertNotEmpty($this->captured, 'Provider was never called');
        $this->assertStringContainsString('cid:', $this->captured['html']);
        $this->assertStringNotContainsString('assets/email-logo.png', $this->captured['html']);

        $logo = collect($this->captured['attachments'] ?? [])->firstWhere('filename', 'email-logo.png');
        $this->assertNotNull($logo, 'Expected the logo as an attachment');
        $this->assertNotEmpty($logo['content_id'] ?? null, 'Logo attachment must carry a content_id for CID rendering');
        $this->assertStringContainsString('cid:'.$logo['content_id'], $this->captured['html']);
    }

    public function test_order_placed_email_carries_the_logo_too(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-TEST-MAIL01',
            'cart_id' => null,
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 7,
            'markup_amount' => 0,
            'total_amount' => 7,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'fulfillment_status' => 'processing',
            'order_status' => 'processing',
            'placed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 7, 'settlement_subtotal_usd' => 7],
        ]);

        $this->sendThroughProvider(new OrderPlacedMail($user, $order), $user->email);

        $logo = collect($this->captured['attachments'] ?? [])->firstWhere('filename', 'email-logo.png');
        $this->assertNotNull($logo, 'Expected the logo as an attachment');
        $this->assertNotEmpty($logo['content_id'] ?? null);
    }
}
