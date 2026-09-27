<?php

namespace Tests\Feature\Shop;

use Tests\TestCase;

/**
 * The product's light-mode ink is one navy in three strengths: the zinc text
 * tokens are remapped to it in app.css so a heading, the line under it and a
 * caption all stay in the same family, on the storefront and the admin alike.
 * Every tier was stepped up on 2026-09-23, when #1f2b45 / #303c54 / #555f72
 * read as washed at the sizes the admin tables use; the hue is unchanged,
 * only the strength.
 */
class StorefrontInkTest extends TestCase
{
    private function block(): string
    {
        preg_match('/Product ink -+[\s\S]*?\.divide-zinc-200/', file_get_contents(resource_path('css/app.css')), $block);

        $this->assertNotEmpty($block, 'The product ink block was not found.');

        return $block[0];
    }

    public function test_strong_ink_is_the_navy(): void
    {
        $block = $this->block();

        foreach (['storefront', 'admin'] as $surface) {
            foreach (['950', '900', '800'] as $tier) {
                $this->assertStringContainsString("html:not(.dark) body.{$surface} .text-zinc-{$tier}", $block);
            }
        }
        $this->assertMatchesRegularExpression('/body\.admin \.text-zinc-800 \{ color: #16223c; \}/', $block);
    }

    public function test_muted_and_faint_ink_are_lighter_steps_of_the_same_navy(): void
    {
        $block = $this->block();

        $this->assertStringContainsString('html:not(.dark) body.storefront .text-zinc-600,', $block);
        $this->assertStringContainsString('html:not(.dark) body.storefront .text-zinc-500,', $block);
        $this->assertMatchesRegularExpression('/body\.admin \.text-zinc-600 \{ color: #243049; \}/', $block);
        $this->assertMatchesRegularExpression('/body\.admin \.text-zinc-500 \{ color: #3d4a63; \}/', $block);

        // Same hue, less strength: each step is lighter than the last, and
        // every tier keeps blue as its strongest channel.
        $channels = fn (string $hex) => array_map(fn (int $at) => hexdec(substr($hex, $at, 2)), [1, 3, 5]);
        $tiers = ['#16223c', '#243049', '#3d4a63'];
        foreach ($tiers as $i => $hex) {
            [$r, $g, $b] = $channels($hex);
            $this->assertGreaterThan($r, $b, "{$hex} is not a navy.");
            $this->assertGreaterThan($g, $b, "{$hex} is not a navy.");
            if ($i > 0) {
                $this->assertGreaterThan(array_sum($channels($tiers[$i - 1])), $r + $g + $b, "{$hex} is not lighter than the tier above it.");
            }
        }
    }

    public function test_the_old_grey_tiers_are_gone(): void
    {
        $block = $this->block();

        foreach (['#4a5a72', '#6b7a90', '#1f2b45', '#303c54', '#555f72'] as $old) {
            $this->assertStringNotContainsString('color: '.$old, $block, $old.' is back');
        }
    }
}
