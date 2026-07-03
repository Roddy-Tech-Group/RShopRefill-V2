<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The shared brand-row drives both the storefront product rows AND the eSIM plan
 * carousel (homepage gift-card rows use carousel mode too). Both must free-scroll
 * with native touch momentum on mobile, exactly like the "How it works" row - so
 * grid mode carries -webkit-overflow-scrolling:touch, and carousel mode must carry
 * momentum WITHOUT scroll-snap or a container scroll-behavior:smooth, either of
 * which overrides that momentum and makes the swipe feel catchy/sluggish.
 */
class BrandRowScrollTest extends TestCase
{
    public function test_grid_mode_product_rows_have_native_momentum_scrolling(): void
    {
        $html = Blade::render('<x-home.brand-row title="Products"><li>card</li></x-home.brand-row>');

        $this->assertStringContainsString('[-webkit-overflow-scrolling:touch]', $html);
    }

    public function test_carousel_mode_keeps_momentum_without_overriding_it(): void
    {
        $html = Blade::render('<x-home.brand-row title="Plans" :carousel="true"><li>plan</li></x-home.brand-row>');

        $this->assertStringContainsString('[-webkit-overflow-scrolling:touch]', $html);
        $this->assertStringNotContainsString('scroll-behavior: smooth', $html);
        $this->assertStringNotContainsString('scroll-snap-type', $html);
    }
}
