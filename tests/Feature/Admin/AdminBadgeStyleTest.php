<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every badge wears one style; only the hue changes.
 *
 * The reference is the customer dashboard's delivery badge: a soft tint pill,
 * no ring, sentence case. Admin badges used to be square-ish
 * (`rounded-[5px]`), bold, UPPERCASE and ringed, so "Delivered" on the
 * customer side and "PAID" on the admin side read as two different components.
 *
 * Tones keep their own colour - a red badge stays red, a purple one purple.
 * What is shared is the shape, size, weight and the absence of a ring.
 */
class AdminBadgeStyleTest extends TestCase
{
    private const BADGE = 'views/components/admin/badge.blade.php';

    private const SHAPE = 'rounded-full px-2.5 py-0.5 text-[11px] font-semibold';

    private function badge(): string
    {
        return File::get(resource_path(self::BADGE));
    }

    public function test_the_canonical_badge_uses_the_shared_shape(): void
    {
        $badge = $this->badge();

        $this->assertStringContainsString(self::SHAPE, $badge);
        $this->assertStringNotContainsString('rounded-[5px]', $badge);
        $this->assertStringNotContainsString('uppercase', $badge);
    }

    /** No tone carries a ring: the tint alone marks the status. */
    public function test_no_tone_carries_a_ring(): void
    {
        $this->assertStringNotContainsString('ring-', $this->badge(), 'badge tones should not ring');
    }

    /**
     * Every tone keeps its own hue and has a dark counterpart. A tone with
     * light-mode colours only renders a pale chip on a dark card.
     */
    public function test_every_tone_keeps_its_hue_in_both_themes(): void
    {
        $badge = $this->badge();

        // These hues are untouched by the palette override, so they can use the
        // plain -50 / -500/15 pairing.
        foreach (['emerald', 'amber', 'red', 'purple'] as $tone) {
            $this->assertStringContainsString("bg-{$tone}-50 text-{$tone}-700", $badge, "{$tone} is missing its light tint");
            $this->assertStringContainsString("dark:bg-{$tone}-500/15 dark:text-{$tone}-300", $badge, "{$tone} is missing its dark tint");
        }
    }

    /**
     * Blue and zinc need special handling; the other tones do not.
     *
     * `.dark .bg-blue-50` and `.dark .text-blue-700` (and the zinc equivalents)
     * are remapped to the ash surface by the palette override, and Tailwind's
     * dark: variant is :where()-wrapped, so it has ZERO specificity and loses.
     * Without the `!` the Payment badge painted ash-on-ash - an invisible pill
     * that only showed while it still had a ring.
     *
     * Blue additionally avoids the 50-400 range entirely: those are neutralised
     * to grey, so `bg-blue-50` / `text-blue-300` would say blue and paint grey.
     */
    public function test_blue_and_zinc_beat_the_palette_override(): void
    {
        $badge = $this->badge();

        $this->assertStringContainsString(
            'bg-blue-600/10 text-blue-700 dark:bg-blue-600/15! dark:text-zinc-200!',
            $badge,
            'the blue tone must use a real blue tint and win against the dark remap'
        );

        $this->assertStringContainsString(
            'bg-zinc-100 text-zinc-700 dark:bg-zinc-500/15! dark:text-zinc-300!',
            $badge,
            'the zinc tone must win against the dark remap'
        );

        // The neutralised range must not appear on a tone that means to be blue.
        $this->assertStringNotContainsString('text-blue-300', $badge);
        $this->assertStringNotContainsString('bg-blue-50 ', $badge);
    }

    /**
     * The inline badges that bypass the component match it, so the dashboard
     * preview and the full list pages cannot disagree.
     */
    public function test_inline_badges_match_the_canonical_shape(): void
    {
        foreach ([
            'views/livewire/admin/dashboard-content.blade.php',
            'views/livewire/dashboard/overview.blade.php',
            'views/livewire/dashboard/transactions.blade.php',
        ] as $view) {
            $content = File::get(resource_path($view));

            $this->assertStringNotContainsString(
                'rounded-[5px] px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ring-1',
                $content,
                "{$view} still uses the old squared, ringed badge"
            );
        }
    }
}
