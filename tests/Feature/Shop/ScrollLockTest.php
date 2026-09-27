<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The shared, reference-counted page scroll lock that every modal, sheet and
 * drawer on the site borrows.
 *
 * It is consumed from roughly fifteen places, so a change to its counter
 * arithmetic reaches all of them at once.
 */
class ScrollLockTest extends TestCase
{
    private function partial(): string
    {
        return File::get(resource_path('views/partials/scroll-lock.blade.php'));
    }

    /**
     * unlock() does nothing when nothing holds a lock.
     *
     * It used to clamp with Math.max(0, counter - 1) and then run the restore
     * branch anyway, including scrollTo(0, savedY) with savedY at its initial
     * 0. Consumers register with Alpine effects and watchers, and an effect
     * runs once on init with its flag false, so every overlay on a page fired
     * exactly that call at boot: a page restored part way down was thrown back
     * to the top, once per overlay.
     */
    public function test_unlocking_an_unlocked_page_does_not_move_it(): void
    {
        $partial = $this->partial();

        $this->assertMatchesRegularExpression(
            '/unlock\(\)\s*\{.*?if \(counter === 0\) \{\s*return;\s*\}/s',
            $partial,
            'unlock() no longer returns early, so a stray call will scroll the page to the top.'
        );

        // The clamp is what hid the imbalance: it kept the counter at zero
        // while still running the restore, so the bug could not announce
        // itself. Decrementing plainly behind the guard cannot underflow.
        $this->assertStringNotContainsString('Math.max(0, counter - 1)', $partial);
        $this->assertStringContainsString('counter -= 1;', $partial);
    }

    /** reset() stays the unconditional cleanup, which is what the guard defers to. */
    public function test_reset_remains_the_unconditional_cleanup(): void
    {
        $partial = $this->partial();

        $this->assertStringContainsString('reset()', $partial);
        $this->assertStringContainsString('counter = 0;', $partial);

        // It runs on SPA navigation, when a modal's close handler never fired.
        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $partial);
    }

    /**
     * Every consumer must watch a SINGLE boolean.
     *
     * x-effect re-runs whenever any dependency changes, so a composite
     * condition can re-enter its still-true branch and lock twice against the
     * counter. A single flag cannot: any change to it flips the branch. The
     * one page that needs a composite condition uses $watch instead.
     */
    public function test_no_consumer_locks_on_a_composite_condition(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            preg_match_all('/x-effect="([^"]*rshopScrollLock[^"]*)"/s', File::get($file->getPathname()), $matches);

            foreach ($matches[1] as $expression) {
                // The condition is everything before the first `?`.
                $condition = trim(explode('?', $expression)[0]);

                if (preg_match('/\|\||&&/', $condition)) {
                    $offenders[] = $file->getRelativePathname().': '.$condition;
                }
            }
        }

        $this->assertSame([], $offenders, "A scroll lock rides on a composite x-effect condition, which double-locks on any change that leaves it true:\n".implode("\n", $offenders));
    }

    /**
     * The lock is released when a navigation STARTS, not when it finishes.
     *
     * Livewire fires three hooks per navigation, and `livewire:navigated` is
     * the last of them: the next page is already built and painted by the time
     * it runs. Releasing only there meant a link tapped inside an open drawer
     * rendered the whole next page under a body still at position:fixed - the
     * dashboard's fixed header measuring against the viewport, the panel under
     * it against the frozen body - and the release afterwards snapped it into
     * place. That snap read as the top of the page shaking on the way in.
     *
     * `livewire:navigate` fires the moment a navigation is triggered, before
     * any HTML is fetched or swapped, so the page is free before the new one
     * lands. The late hook stays as the net for back / forward and redirects.
     */
    public function test_the_lock_is_released_before_the_next_page_is_swapped_in(): void
    {
        $partial = $this->partial();

        foreach (['livewire:navigate', 'livewire:navigated'] as $hook) {
            $this->assertStringContainsString(
                "document.addEventListener('{$hook}', () => window.rshopScrollLock?.reset());",
                $partial,
                "nothing releases the lock on {$hook}"
            );
        }

        // The early hook comes first, so a reader meets the release that
        // matters before the one that is only a fallback.
        $this->assertLessThan(
            strpos($partial, "addEventListener('livewire:navigated'"),
            strpos($partial, "addEventListener('livewire:navigate'"),
            'the early release should be listed first'
        );

        // Released, not unlocked: reset() clears the body whatever the counter
        // says, where unlock() only steps one holder down and would leave the
        // page frozen if two overlays were open.
        $this->assertStringNotContainsString("livewire:navigate', () => window.rshopScrollLock?.unlock()", $partial);
    }
}
