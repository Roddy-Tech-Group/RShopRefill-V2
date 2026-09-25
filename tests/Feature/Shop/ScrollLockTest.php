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
}
