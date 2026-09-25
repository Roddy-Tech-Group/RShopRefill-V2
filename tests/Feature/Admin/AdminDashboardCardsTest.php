<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Admin dashboard cards sit on the page without an outline.
 *
 * The card surfaces carried `ring-1 ring-zinc-100` (and a dark counterpart) on
 * top of their shadow. The shadow already separates a card from the page, so
 * the ring only added a hairline that read as a border.
 *
 * The skeleton is checked alongside the real component: it stands in until the
 * metrics query returns, so if only one of the two loses the ring, every card
 * visibly gains or drops an edge at the moment the content swaps in.
 *
 * Dropdowns, avatars and badges keep their rings - those are floating or inline
 * elements that need their own edge, and they are not cards.
 */
class AdminDashboardCardsTest extends TestCase
{
    private const CONTENT = 'views/livewire/admin/dashboard-content.blade.php';

    private const SKELETON = 'views/components/skeletons/admin-dashboard.blade.php';

    /** The card signature: a rounded surface with the standard card shadow. */
    private const CARD_SIGNATURE = 'shadow-sm shadow-zinc-900/5 ring-1 ring-zinc-100';

    public function test_no_dashboard_card_carries_a_ring(): void
    {
        foreach ([self::CONTENT, self::SKELETON] as $view) {
            $this->assertStringNotContainsString(
                self::CARD_SIGNATURE,
                File::get(resource_path($view)),
                "{$view} still rings a card surface"
            );
        }
    }

    /**
     * The dark counterpart has to go with the light one, or dark mode keeps
     * the edge. Scoped to card lines: the dropdown panels legitimately keep
     * `dark:ring-zinc-700/60`, so a file-wide search would fail on those.
     */
    public function test_no_dashboard_card_carries_a_dark_ring(): void
    {
        foreach ([self::CONTENT, self::SKELETON] as $view) {
            foreach (explode("\n", File::get(resource_path($view))) as $number => $line) {
                // A card surface: the 20px radius paired with the card shadow.
                // Dropdowns use shadow-xl, so they are not caught here.
                if (! str_contains($line, 'rounded-[20px]') || ! str_contains($line, 'shadow-sm')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'ring-',
                    $line,
                    "{$view} line ".($number + 1).' rings a card surface'
                );
            }
        }
    }

    /**
     * The shadow stays. It is what lifts a card off the page now that the
     * hairline is gone; dropping both would leave the cards floating flat.
     */
    public function test_the_cards_keep_their_shadow(): void
    {
        $content = File::get(resource_path(self::CONTENT));

        $this->assertStringContainsString('shadow-sm shadow-zinc-900/5', $content);
    }

    /** Floating menus are not cards and keep their own edge. */
    public function test_dropdown_panels_keep_their_ring(): void
    {
        $content = File::get(resource_path(self::CONTENT));

        $this->assertMatchesRegularExpression(
            '/shadow-xl[^"]*ring-1/',
            $content,
            'the dropdown panels should still carry a ring'
        );
    }
}
