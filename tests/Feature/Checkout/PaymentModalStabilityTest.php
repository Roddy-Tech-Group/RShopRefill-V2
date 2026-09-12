<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;

/**
 * The checkout payment wizard must survive the customer tapping a field inside
 * it - most visibly the wallet transaction-PIN step, where the on-screen
 * keyboard opening used to close the modal in the installed PWA.
 *
 * Two global handlers can tear the modal down and both use
 * `body{position:fixed}` (the shared rshopScrollLock) as their "a dialog is
 * open, stand down" signal:
 *   1. resources/js/app.js dispatches a synthetic Escape on window scroll.
 *   2. components/pull-to-refresh reloads the page on a downward drag, and it
 *      only runs in standalone/PWA mode - which is why the bug never showed on
 *      the normal website.
 */
class PaymentModalStabilityTest extends TestCase
{
    private function checkoutBlade(): string
    {
        return file_get_contents(resource_path('views/shop/checkout.blade.php'));
    }

    public function test_payment_wizard_takes_the_shared_scroll_lock_while_open(): void
    {
        $blade = $this->checkoutBlade();

        $this->assertStringContainsString(
            "this.\$watch('open', (isOpen) => {",
            $blade,
            'The payment wizard must watch its open state to drive the scroll lock.'
        );
        $this->assertStringContainsString(
            'window.rshopScrollLock?.lock();',
            $blade,
            'Opening the payment wizard must lock body scroll, or the global scroll handlers close it mid-payment.'
        );
        $this->assertStringContainsString(
            'window.rshopScrollLock?.unlock();',
            $blade,
            'Closing the payment wizard must release the scroll lock.'
        );
    }

    public function test_payment_wizard_ignores_synthetic_escape_events(): void
    {
        $this->assertStringContainsString(
            'x-on:keydown.escape.window="$event.isTrusted && open && closeModal()"',
            $this->checkoutBlade(),
            'app.js fires an untrusted Escape on page scroll; only a real keypress may close the wizard.'
        );
    }

    public function test_pull_to_refresh_stands_down_inside_dialogs_and_while_typing(): void
    {
        $ptr = file_get_contents(resource_path('views/components/pull-to-refresh.blade.php'));

        $this->assertStringContainsString(
            'node.closest(\'[role="dialog"], [aria-modal="true"]\')',
            $ptr,
            'A drag starting inside an open dialog must never trigger a page reload.'
        );
        $this->assertStringContainsString(
            'isTyping()',
            $ptr,
            'Pull-to-refresh must stand down while the on-screen keyboard is up.'
        );
        $this->assertMatchesRegularExpression(
            '/if \(refreshing \|\| isLocked\(\) \|\| isTyping\(\) \|\| inDialog\(ev\.target\)/',
            $ptr,
            'The touchstart guard must check the dialog and keyboard conditions.'
        );
    }

    public function test_payment_wizard_content_is_still_marked_as_a_dialog(): void
    {
        // The pull-to-refresh guard keys off these attributes, so losing them
        // would silently re-arm the reload behind the payment modal.
        $blade = $this->checkoutBlade();

        $this->assertStringContainsString('role="dialog"', $blade);
        $this->assertStringContainsString('aria-modal="true"', $blade);
    }
}
