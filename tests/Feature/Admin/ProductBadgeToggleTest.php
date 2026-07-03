<?php

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * The Featured/Popular badge control in the product drawer is a single persistent
 * toggle: a red X stays put while the badge is ON (so the drawer shows at a glance
 * that the product is flagged, and the X removes it), a + adds it. It must NOT be
 * the old label-wrapped checkbox, where clicking the badge text flipped a hidden
 * checkbox and toggled the flag by accident.
 *
 * The admin catalog page leans on MySQL-only JSON functions, so it can't render
 * under the SQLite test DB - assert against the drawer markup directly instead.
 */
class ProductBadgeToggleTest extends TestCase
{
    public function test_drawer_uses_a_persistent_state_aware_badge_toggle(): void
    {
        $markup = file_get_contents(resource_path('views/admin/products.blade.php'));

        // Single control whose aria-label (and icon) flips with the flag state.
        $this->assertStringContainsString("data.isFeatured ? 'Remove Featured badge' : 'Mark as Featured'", $markup);
        $this->assertStringContainsString("data.isPopular ? 'Remove Popular badge' : 'Mark as Popular'", $markup);
    }

    public function test_the_stray_click_checkbox_is_gone(): void
    {
        $markup = file_get_contents(resource_path('views/admin/products.blade.php'));

        // The old <label> + hidden checkbox flipped the flag when the badge text
        // was clicked. It must not come back.
        $this->assertStringNotContainsString('x-model="data.isFeatured"', $markup);
        $this->assertStringNotContainsString('x-model="data.isPopular"', $markup);
    }
}
