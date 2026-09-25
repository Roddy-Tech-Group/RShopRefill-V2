<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The storefront's light-mode ink is one navy in three strengths: the zinc
 * text tokens are remapped to it in app.css so a heading, the line under it
 * and a caption all stay in the same family. The muted and faint tiers are
 * that navy laid over white at 85% and 70%; they used to be 72% and 55%,
 * which read as grey rather than as a softer navy (raised 2026-09-20).
 */
class StorefrontInkTest extends TestCase
{
    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    public function test_strong_ink_is_the_navy(): void
    {
        $this->assertStringContainsString('html:not(.dark) body.storefront .text-zinc-800 { color: #0c1a36; }', $this->css());
    }

    public function test_muted_and_faint_ink_are_the_navy_at_85_and_70_percent(): void
    {
        $css = $this->css();

        $this->assertStringContainsString('html:not(.dark) body.storefront .text-zinc-600 { color: #303c54; }', $css);
        $this->assertStringContainsString('html:not(.dark) body.storefront .text-zinc-500 { color: #555f72; }', $css);

        // The two shades are the navy mixed with white, nothing else.
        foreach (['#303c54' => 0.85, '#555f72' => 0.70] as $hex => $opacity) {
            foreach ([[12, 1], [26, 3], [54, 5]] as [$navy, $at]) {
                $channel = hexdec(substr($hex, $at, 2));
                $this->assertEqualsWithDelta($navy * $opacity + 255 * (1 - $opacity), $channel, 1.0, "{$hex} is not the navy at {$opacity}.");
            }
        }
    }

    /** The grey the tiers used to be must not come back into the block. */
    public function test_the_old_grey_tiers_are_gone(): void
    {
        preg_match('/Storefront ink -+[\s\S]*?\.divide-zinc-200/', $this->css(), $block);

        $this->assertNotEmpty($block, 'The storefront ink block was not found.');
        $this->assertStringNotContainsString('color: #4a5a72', $block[0]);
        $this->assertStringNotContainsString('color: #6b7a90', $block[0]);
    }
}
