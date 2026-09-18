<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;

/**
 * The overview's lazy-load skeleton mirrors the real page, so nothing jumps
 * when the component boots. It had drifted: a trust strip and a Shop by
 * Category grid the page no longer has, three cards where the page has two,
 * and blue bordered cards where the page's are white.
 */
class OverviewSkeletonTest extends TestCase
{
    private function skeleton(): string
    {
        return view('components.skeletons.dashboard-overview')->render();
    }

    private function overview(): string
    {
        return file_get_contents(resource_path('views/livewire/dashboard/overview.blade.php'));
    }

    public function test_sections_the_page_no_longer_has_are_gone(): void
    {
        $source = file_get_contents(resource_path('views/components/skeletons/dashboard-overview.blade.php'));

        $this->assertStringNotContainsString('Trust strip', $source);
        $this->assertStringNotContainsString('Shop by Category', $source);
        $this->assertStringNotContainsString('xl:grid-cols-3', $source);
        $this->assertStringNotContainsString('bg-[#eff6ff]', $source);
    }

    /** Each grid the skeleton draws is a grid the real page actually uses. */
    public function test_the_grids_match_the_real_overview(): void
    {
        $skeleton = $this->skeleton();
        $overview = $this->overview();

        foreach ([
            'grid grid-cols-1 gap-6 lg:col-span-12 lg:grid-cols-3', // wallet | orders | Rcoin row, never reflows
            'mt-4 grid grid-cols-4 gap-3 sm:grid-cols-8', // desktop Quick Actions
            'mt-4 grid grid-cols-4 gap-3',             // mobile Quick Actions
            'shadow-md shadow-zinc-900/[0.06] lg:col-span-8 dark:shadow-none', // Quick Actions card
            'flex flex-col gap-6 lg:col-span-4 lg:min-h-0 lg:[contain:size]', // promo + transactions
        ] as $grid) {
            $this->assertStringContainsString($grid, $overview, 'The overview no longer uses '.$grid.'; update the skeleton.');
            $this->assertStringContainsString($grid, $skeleton, 'The skeleton is missing '.$grid.'.');
        }

        // The page's card shell.
        $this->assertStringContainsString('rounded-[15px] bg-[#ffffff] p-5 shadow-md shadow-zinc-900/[0.06] dark:shadow-none', $skeleton);
    }

    /** Livewire lazy placeholders must render a single root element. */
    public function test_the_placeholder_has_a_single_root(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<body>'.$this->skeleton().'</body>');

        $roots = array_filter(
            iterator_to_array($dom->getElementsByTagName('body')->item(0)->childNodes),
            fn ($node) => $node->nodeType === XML_ELEMENT_NODE
        );

        $this->assertCount(1, $roots);
    }
}
