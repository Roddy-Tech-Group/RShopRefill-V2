<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checkout paints a skeleton until the cart store's first fetch resolves.
 *
 * The page renders its basket from the Alpine store, so the server sends none
 * of it: a shopper who has just committed to paying used to meet an empty card
 * with a spinner in it. The skeleton stands in for the real page, and the
 * property worth pinning is that it stands in for THE REAL ONE - it repeats
 * the content's own grid, cards and control sizes, so the swap lands on boxes
 * that are already the right shape instead of resizing the page.
 */
class CheckoutSkeletonTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        return file_get_contents(resource_path('views/shop/checkout.blade.php'));
    }

    /** The skeleton block, from its x-show to the end of its grid. */
    private function skeleton(): string
    {
        $source = $this->source();
        $start = strpos($source, '<div x-show="!$store.cart.hydrated"');
        $this->assertNotFalse($start, 'The loading state is gone.');

        $end = strpos($source, '{{-- Empty cart', $start);
        $this->assertNotFalse($end);

        return substr($source, $start, $end - $start);
    }

    public function test_the_spinner_is_replaced_by_a_skeleton(): void
    {
        $skeleton = $this->skeleton();

        $this->assertStringContainsString('<x-skeleton', $skeleton, 'The loading state draws no skeleton.');
        $this->assertStringNotContainsString('animate-spin', $skeleton, 'The spinner is back.');

        // A picture of content that is not there yet: the state that replaces
        // it is what a screen reader should be told about.
        $this->assertStringContainsString('aria-hidden="true"', $skeleton);
    }

    /**
     * No x-cloak on this one block. It is the state that has to paint before
     * Alpine boots; the two real states below it carry x-cloak so they stay
     * hidden until the store can say which of them is true.
     */
    public function test_the_skeleton_paints_before_alpine_boots(): void
    {
        $this->assertStringNotContainsString('x-cloak', $this->skeleton());

        $source = $this->source();
        $this->assertStringContainsString('x-show="$store.cart.hydrated && $store.cart.count === 0" x-cloak', $source);
        $this->assertStringContainsString('x-show="$store.cart.hydrated && $store.cart.count > 0" x-cloak', $source);
    }

    /**
     * Each shared string appears twice in the view: once in the skeleton, once
     * in the content it stands in for. Two is the assertion - one means the
     * skeleton drifted from the layout, and the reflow it was added to prevent
     * is back.
     *
     * @return array<string, array{0: string}>
     */
    public static function sharedShapes(): array
    {
        return [
            'the two-column grid' => ['grid grid-cols-1 gap-5 lg:grid-cols-5 lg:items-start'],
            'the order-details card' => ['pure-card self-start rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:sticky lg:top-6 lg:col-span-2'],
            'the payment card' => ['pure-card rounded-[20px] bg-white/70 dark:bg-[#0c1a36]/60 p-5 ring-1 ring-zinc-200 backdrop-blur-xl dark:ring-white/10 sm:p-6 lg:col-span-3'],
            'the item row' => ['flex items-start gap-3 py-4'],
            'the method-tile grid' => ['mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3'],
        ];
    }

    #[DataProvider('sharedShapes')]
    public function test_the_skeleton_repeats_the_real_layout(string $shape): void
    {
        $this->assertSame(
            2,
            substr_count($this->source(), $shape),
            "Expected this exactly twice - once in the skeleton, once in the content: {$shape}"
        );
    }

    /** The placeholders are the size of the controls they stand in for. */
    public function test_the_placeholders_match_the_real_control_sizes(): void
    {
        $skeleton = $this->skeleton();
        $source = $this->source();

        // The method tile is min-h-[74px] in the real grid.
        $this->assertStringContainsString('h-[74px]', $skeleton);
        $this->assertStringContainsString('min-h-[74px]', $source);

        // The brand tile is a 16:10 plate at the same widths.
        $this->assertStringContainsString('aspect-[16/10] w-28 shrink-0 sm:w-32', $skeleton);
        $this->assertStringContainsString('aspect-[16/10] w-28 shrink-0', $source);

        // The action is the 44px pill the submit uses.
        $this->assertStringContainsString('h-11 w-full', $skeleton);
        $this->assertStringContainsString('flex h-11 w-full items-center justify-center', $source);
    }
}
