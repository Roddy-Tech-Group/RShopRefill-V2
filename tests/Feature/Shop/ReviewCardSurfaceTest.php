<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A review is a card in both themes.
 *
 * The shared review card once drew its edge with a border and had no fill.
 * Removing the border, per the rule that cards carry shadows and never
 * borders, left it transparent: in light mode a review was text straight on
 * the page, and only dark mode's elevated grey made it look like a card. The
 * card and the collector card at the front of the homepage carousel now wear
 * the project's card surface.
 */
class ReviewCardSurfaceTest extends TestCase
{
    private const SURFACE = 'bg-white p-5 shadow-md shadow-zinc-900/[0.06]';

    public function test_the_review_card_is_a_white_card_with_a_shadow(): void
    {
        $partial = File::get(resource_path('views/shop/_review-card.blade.php'));
        // The tag's Alpine expressions contain ">" characters, so read its class
        // attribute rather than slicing to the first closing bracket.
        $classAt = strpos($partial, 'class="', strpos($partial, '<article'));
        $article = substr($partial, $classAt, strpos($partial, '"', $classAt + 7) - $classAt);

        $this->assertStringContainsString(self::SURFACE, $article);
        $this->assertStringContainsString('dark:shadow-none', $article);
        $this->assertStringNotContainsString('bg-transparent', $article);
        $this->assertStringNotContainsString('border', $article);
        $this->assertStringNotContainsString('ring-1', $article);
    }

    public function test_the_collector_card_matches_the_review_cards(): void
    {
        $carousel = File::get(resource_path('views/components/home/customer-reviews.blade.php'));

        $this->assertStringContainsString('<article class="flex w-72 shrink-0 flex-col rounded-[12px] '.self::SURFACE.' dark:shadow-none">', $carousel);
        $this->assertStringNotContainsString('ring-zinc-200 dark:ring-[#24364f]', $carousel);
    }
}
