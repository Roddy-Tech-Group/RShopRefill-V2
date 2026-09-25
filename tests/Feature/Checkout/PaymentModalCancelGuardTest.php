<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A payment in flight is hard to cancel by mistake.
 *
 * The backdrop, Escape and the close button no longer close the wizard while
 * a payment is in progress. They raise a prompt over it, with "keep paying"
 * as the blue button that takes focus and the cancel a quiet red text button
 * beneath. A deliberate cancel tells the server, so the attempt is filed as
 * cancelled. Leaving the page mid-payment gets the browser's own check. The
 * browser confirm this replaces is gone, in every locale.
 */
class PaymentModalCancelGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function checkout(?string $locale = null): string
    {
        $request = $this->actingAs(User::factory()->create());

        if ($locale !== null) {
            $request = $request->withSession(['locale' => $locale]);
        }

        return $request->get('/checkout')->assertOk()->getContent();
    }

    public function test_every_casual_way_out_asks_first(): void
    {
        $html = $this->checkout();

        $this->assertStringContainsString('x-on:keydown.escape.window="$event.isTrusted && open && requestClose()"', $html);
        $this->assertStringContainsString('class="fixed inset-0 bg-zinc-950/40 backdrop-blur-md"', $html);
        $this->assertMatchesRegularExpression('/backdrop-blur-md"\s+@click="requestClose\(\)"/', $html);
        // The close button is a component, so its attributes may land in any order.
        $this->assertMatchesRegularExpression('/<button(?=[^>]*@click="requestClose\(\)")(?=[^>]*absolute right-4 top-4)[^>]*>/s', $html);

        // Leaving the page mid-payment gets the browser's own check.
        $this->assertStringContainsString("x-on:beforeunload.window=\"if (paymentInFlight()) { \$event.preventDefault(); \$event.returnValue = '' }\"", $html);

        // The browser confirm is gone.
        $this->assertStringNotContainsString('window.confirm(', $html);
    }

    public function test_the_prompt_favours_staying_and_cancels_deliberately(): void
    {
        $html = $this->checkout();

        $this->assertStringContainsString('role="alertdialog"', $html);
        $this->assertStringContainsString('x-show="cancelPrompt"', $html);
        $this->assertStringContainsString(__('checkout.cancel_prompt_title'), $html);
        $this->assertStringContainsString(__('checkout.cancel_prompt_body'), $html);

        // Keep paying is the blue button and the one that takes focus; cancel is
        // a red text button and the deliberate action.
        $this->assertMatchesRegularExpression('/<button type="button" x-ref="keepPayingButton" @click="cancelPrompt = false" class="[^"]*bg-blue-600[^"]*">\s*'.preg_quote(__('checkout.cancel_prompt_keep'), '/').'/s', $html);
        $this->assertStringContainsString('this.$refs.keepPayingButton?.focus()', $html);
        $this->assertMatchesRegularExpression('/<button type="button" @click="cancelPayment\(\)" :disabled="cancelling" class="[^"]*text-red-600/', $html);

        // A deliberate cancel tells the server which session to file.
        $this->assertStringContainsString('fetch(`/api/payment-sessions/${id}/cancel`', $html);
        $this->assertStringContainsString('async cancelPayment()', $html);
    }

    public function test_only_a_live_payment_is_guarded(): void
    {
        $html = $this->checkout();

        $this->assertStringContainsString("return !! this.session && ! ['idle', 'success', 'error', 'backoff_notice', 'email_notice', 'kyc_required'].includes(this.paymentState);", $html);
    }

    public function test_the_prompt_ships_in_french_and_the_old_line_is_gone_everywhere(): void
    {
        $html = $this->checkout('fr');

        $this->assertStringContainsString(__('checkout.cancel_prompt_title', [], 'fr'), $html);
        $this->assertStringContainsString(__('checkout.cancel_prompt_keep', [], 'fr'), $html);
        $this->assertStringNotContainsString('Cancel this payment?', $html);

        foreach (File::glob(lang_path('*/checkout.php')) as $file) {
            $this->assertStringNotContainsString('close_in_flight_confirm', File::get($file), basename(dirname($file)).' still carries the browser confirm line');
            $this->assertStringContainsString("'cancel_prompt_cancel' =>", File::get($file), basename(dirname($file)).' lacks the cancel prompt copy');
        }
    }
}
