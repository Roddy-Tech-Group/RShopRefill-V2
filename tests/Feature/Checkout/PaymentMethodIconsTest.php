<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;

/**
 * Every payment-method icon referenced on the checkout page must resolve to a
 * real asset file. A wrong/renamed path renders a broken image in the method
 * picker (the Mobile Money icon regressed this way).
 */
class PaymentMethodIconsTest extends TestCase
{
    public function test_all_checkout_payment_icons_exist_on_disk(): void
    {
        $blade = file_get_contents(resource_path('views/shop/checkout.blade.php'));

        preg_match_all("/icon:\s*'(\/assets\/[^']+)'/", $blade, $matches);

        $this->assertNotEmpty($matches[1], 'Expected payment-method icons in the checkout view.');

        foreach (array_unique($matches[1]) as $path) {
            $file = public_path(urldecode(ltrim($path, '/')));
            $this->assertFileExists($file, "Checkout payment icon points to a missing asset: {$path}");
        }
    }
}
