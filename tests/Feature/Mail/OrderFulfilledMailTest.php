<?php

namespace Tests\Feature\Mail;

use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\Enums\TransactionCategory;
use App\Domain\Wallet\Services\WalletService;
use App\Mail\OrderFulfilledMail;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderFulfilledMailTest extends TestCase
{
    use RefreshDatabase;

    private function makeFulfilledItem(array $payload, array $productSnapshot = [], string $provider = 'airalo'): OrderItem
    {
        $user = User::factory()->create(['name' => 'Divine Ofeh']);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'RSR-TEST-000001',
            'cart_id' => null,
            'settlement_currency' => 'USD',
            'display_currency' => 'USD',
            'subtotal_amount' => 7.00,
            'markup_amount' => 0,
            'total_amount' => 7.00,
            'payment_method' => 'card',
            'payment_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'order_status' => 'completed',
            'placed_at' => now(),
            'completed_at' => now(),
            'metadata' => ['exchange_rate' => 1.0, 'settlement_total_usd' => 7.00, 'settlement_subtotal_usd' => 7.00],
        ]);

        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $subcategory = Subcategory::factory()->create(['category_id' => $category->id]);

        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => $provider,
            'quantity' => 1,
            'display_currency' => 'USD',
            'display_amount' => 7.00,
            'provider_cost_usd' => 5.00,
            'markup_amount' => 0,
            'subtotal_amount' => 7.00,
            'product_snapshot' => array_merge(['name' => 'United States eSIM', 'country_code' => 'US'], $productSnapshot),
            'variant_snapshot' => ['face_value' => 7, 'currency' => 'USD'],
            'fulfillment_status' => 'fulfilled',
            'fulfillment_payload' => $payload,
            'delivered_at' => now(),
        ]);
    }

    public function test_esim_delivery_email_renders_esim_details_not_gift_card_copy(): void
    {
        $item = $this->makeFulfilledItem([
            'qrcode_url' => 'https://sandbox.airalo.com/qr/test-qr.png',
            'lpa' => 'wbg.prod.ondemandconnectivity.com',
            'iccid' => '8944465400000000000',
            'sharing_link' => 'https://esims.cloud/rshoprefill/test-share/instructions',
            'sharing_access_code' => 'ACCESS-1234',
            'direct_install_url' => 'https://esimsetup.apple.com/esim_qrcode_provisioning?carddata=test',
            'esim' => [
                'iccid' => '8944465400000000000',
                'lpaUrl' => 'wbg.prod.ondemandconnectivity.com',
                'manualActivationCode' => 'K2-1ABCDE-3FGHIJ',
                'directInstallUrl' => 'https://esimsetup.apple.com/esim_qrcode_provisioning?carddata=test',
                'sharingLink' => 'https://esims.cloud/rshoprefill/test-share/instructions',
                'sharingAccessCode' => 'ACCESS-1234',
            ],
        ]);

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('Your eSIM is ready', $html);
        $this->assertStringNotContainsString('Your gift card is ready', $html);
        $this->assertStringContainsString('https://sandbox.airalo.com/qr/test-qr.png', $html);
        $this->assertStringContainsString('SM-DP+ Address', $html);
        $this->assertStringContainsString('wbg.prod.ondemandconnectivity.com', $html);
        $this->assertStringContainsString('K2-1ABCDE-3FGHIJ', $html);
        $this->assertStringContainsString('https://esims.cloud/rshoprefill/test-share/instructions', $html);

        // Layout contract: Access code row > Rcoin earned > action buttons
        // (install on iPhone > Manage your eSIM > Top up eSIM > View in your
        // dashboard) > SM-DP+ note > footer text.
        $positions = [
            'Access code' => strpos($html, 'Access code'),
            'Rcoin cashback' => strpos($html, 'Rcoin cashback'),
            'install on iPhone' => strpos($html, 'install on iPhone'),
            'Manage your eSIM' => strpos($html, 'Manage your eSIM'),
            'Top up eSIM' => strpos($html, 'Top up eSIM'),
            'View in your dashboard' => strpos($html, 'View in your dashboard'),
            'The SM-DP+ address is not a website' => strpos($html, 'The SM-DP+ address is not a website'),
            'Treat these installation details' => strpos($html, 'Treat these installation details'),
        ];
        foreach ($positions as $label => $pos) {
            $this->assertNotFalse($pos, "Expected '{$label}' in the eSIM email");
        }
        $this->assertSame(array_keys($positions), array_keys(collect($positions)->sort()->all()), 'eSIM email actions are out of order');
    }

    public function test_esim_email_without_sharing_link_falls_back_to_dashboard(): void
    {
        $item = $this->makeFulfilledItem([
            'lpa' => 'wbg.prod.ondemandconnectivity.com',
            'iccid' => '8944465400000000000',
        ]);

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('Your eSIM is ready', $html);
        $this->assertStringContainsString('Manage your eSIM', $html);
        $this->assertStringContainsString('/dashboard/orders', $html);
        // The SM-DP+ address must appear as an install credential, never as a
        // gift-card "Code" (the bug this template rewrite fixes).
        $this->assertStringContainsString('SM-DP+ Address', $html);
    }

    public function test_gift_card_email_renders_code_and_pin(): void
    {
        $item = $this->makeFulfilledItem(
            ['code' => 'GC-TEST-12345', 'pin' => '9876'],
            ['name' => 'Amazon US', 'country_code' => 'US'],
            'zendit',
        );

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('Your gift card is ready', $html);
        $this->assertStringNotContainsString('Your eSIM is ready', $html);
        $this->assertStringContainsString('GC-TEST-12345', $html);
        $this->assertStringContainsString('9876', $html);
    }

    public function test_pin_redemption_url_card_email_surfaces_the_redemption_link_and_pin(): void
    {
        // Virtual prepaid Visa (VISA_AWARDS): Zendit delivers a 6-digit PIN under
        // `epin` plus a registration link - and the link is the buyer's ONLY way
        // to claim the card. It was being captured but never rendered, leaving
        // customers stuck (the bug this fixes). The PIN must also read as a PIN,
        // not leak through the generic code fallback as a "Code".
        $item = $this->makeFulfilledItem(
            ['epin' => '585634', 'redemption_url' => 'https://giftonline.biz/_myglobe/ltg_-GKf'],
            ['name' => 'Visa (Prepaid) (US)', 'country_code' => 'US'],
            'zendit',
        );

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('Your gift card is ready', $html);
        // The registration link must be delivered as a clickable CTA.
        $this->assertStringContainsString('https://giftonline.biz/_myglobe/ltg_-GKf', $html);
        $this->assertStringContainsString('Redeem your card', $html);
        // The epin must surface as the PIN.
        $this->assertStringContainsString('585634', $html);
    }

    public function test_delivery_email_previews_rcoin_cashback_before_the_reward_lands(): void
    {
        // Pin the rate so the preview is independent of the default: $7 order
        // x 1% cashback = $0.07, at $0.01/Rcoin = 7 Rcoin.
        Setting::set('rcoin_usd_rate', 0.01);
        Setting::set('cashback_percentage', 1.0);
        $item = $this->makeFulfilledItem(['code' => 'GC-TEST-12345']);

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('Rcoin cashback', $html);
        $this->assertStringContainsString('+7 Rcoin', $html);
        $this->assertStringContainsString('lands in your Rcoin wallet', $html);
    }

    public function test_esim_qr_is_embedded_inline_on_a_real_send(): void
    {
        // Hot-linking the provider's signed QR URL fails through email image
        // proxies (and leaks the supplier domain), so a real send must fetch
        // the PNG and embed it as an inline CID attachment instead.
        Http::fake([
            'www.airalo.com/*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $item = $this->makeFulfilledItem([
            'qrcode_url' => 'https://www.airalo.com/qr?id=123&signature=abc',
            'lpa' => 'wbg.prod.ondemandconnectivity.com',
            'iccid' => '8944465400000000000',
        ]);

        Mail::to('customer@example.test')->send(new OrderFulfilledMail($item));

        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $email = $sent->first()->getOriginalMessage();
        $this->assertStringContainsString('cid:', $email->getHtmlBody());
        $this->assertStringNotContainsString('www.airalo.com', $email->getHtmlBody());

        $inline = collect($email->getAttachments())->first(fn ($part) => $part->getFilename() === 'esim-qr.png');
        $this->assertNotNull($inline, 'Expected the QR PNG to be attached inline as esim-qr.png');
        $this->assertSame('fake-png-bytes', $inline->getBody());

        // The brand logo must ride inside the email as well - remote fetches
        // through mail-client image proxies are what broke it in production.
        $logo = collect($email->getAttachments())->first(fn ($part) => $part->getFilename() === 'email-logo.png');
        $this->assertNotNull($logo, 'Expected the logo to be attached inline as email-logo.png');
    }

    public function test_delivery_email_shows_the_credited_rcoin_amount_when_available(): void
    {
        $item = $this->makeFulfilledItem(['code' => 'GC-TEST-12345']);
        $order = $item->order;

        $walletService = app(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($order->user, Currency::RCOIN);
        $walletService->credit(
            wallet: $wallet,
            amount: 42,
            category: TransactionCategory::RewardCashback,
            description: 'Cashback reward for test',
            idempotencyKey: "reward-cashback-{$order->id}",
        );

        $html = (new OrderFulfilledMail($item))->render();

        $this->assertStringContainsString('+42 Rcoin', $html);
        $this->assertStringContainsString('already in your Rcoin wallet', $html);
    }
}
