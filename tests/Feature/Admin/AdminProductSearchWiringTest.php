<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The product search box and its suggestion panel must agree with each other.
 *
 * Two separate defects made the panel list a different brand than the one being
 * typed:
 *
 *  1. The debounce sat on the model (`x-model.debounce.250ms`) while `@input`
 *     fired immediately, so `suggest()` read `query` BEFORE it updated and
 *     searched the previous value.
 *  2. `results` was only replaced when a response arrived, and the panel is
 *     shown while `loading`, so the previous term's rows stayed on screen for
 *     the whole round trip.
 *
 * The backend was never at fault - it filters on name, brand_key, country and
 * sku correctly.
 */
class AdminProductSearchWiringTest extends TestCase
{
    private const VIEW = 'views/admin/products.blade.php';

    private function productsView(): string
    {
        return File::get(resource_path(self::VIEW));
    }

    /** The debounce belongs on the call, so suggest() sees the typed value. */
    public function test_the_debounce_is_on_the_call_not_the_model(): void
    {
        $view = $this->productsView();

        $this->assertStringContainsString('@input.debounce.250ms="suggest()"', $view);
        $this->assertStringNotContainsString('x-model.debounce.250ms="query"', $view);
    }

    /** Rows belonging to another term are dropped before the new fetch. */
    public function test_stale_rows_are_cleared_when_the_term_changes(): void
    {
        $view = $this->productsView();

        $this->assertStringContainsString('resultsFor', $view);
        $this->assertStringContainsString(
            'if (this.resultsFor !== q) { this.results = []; }',
            $view,
            'the panel must drop rows from a previous term before fetching'
        );
        $this->assertStringContainsString('this.resultsFor = q;', $view);
    }

    /** The existing race guard stays: a slow response must not win. */
    public function test_the_out_of_order_response_guard_survives(): void
    {
        $view = $this->productsView();

        $this->assertStringContainsString('const mySeq = ++this._seq;', $view);
        $this->assertStringContainsString('if (mySeq !== this._seq) { return; }', $view);
    }

    /** Focus ring uses the same blue the rest of the admin settles on. */
    public function test_the_search_focus_uses_the_shared_blue(): void
    {
        $view = $this->productsView();

        $this->assertStringContainsString('focus:border-blue-600 focus:ring-2 focus:ring-blue-600/15', $view);
        $this->assertStringNotContainsString('focus:border-blue-500 focus:ring-2 focus:ring-blue-500/15', $view);
    }
}
