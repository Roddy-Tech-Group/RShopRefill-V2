<?php

namespace Tests\Feature\Nav;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The dark-theme icon is a moon that waxes from half to full and wanes back.
 *
 * It used to be one half-disc that flipped sides every few seconds, which read
 * as a spinning coin. Now the left half stays lit and a second half-disc grows
 * out of the centre line and shrinks again, so the curved edge sweeps across
 * the face the way a terminator does: half, full, half.
 */
class ThemeDarkIconTest extends TestCase
{
    private function icon(): string
    {
        return Blade::render('<x-icons.theme-dark class="h-5 w-5" />');
    }

    /** The waxing half's keyframes, comments stripped. */
    private function phase(): string
    {
        preg_match('/@keyframes td-phase \{(.*?)\n\s*\}/s', $this->icon(), $m);
        $this->assertNotEmpty($m, 'The td-phase keyframes are missing.');

        return preg_replace('#/\*.*?\*/#s', '', $m[1]);
    }

    public function test_the_moon_is_two_half_discs_on_an_outlined_face(): void
    {
        $svg = $this->icon();

        $this->assertStringContainsString('<circle cx="12" cy="12" r="8.6" stroke="currentColor"', $svg);

        // Sweep 0 arcs down the left, sweep 1 down the right. Both take the text colour.
        $this->assertStringContainsString('<path d="M12 3.4 A8.6 8.6 0 0 0 12 20.6 Z" fill="currentColor"/>', $svg);
        $this->assertStringContainsString('<path class="td-wax" d="M12 3.4 A8.6 8.6 0 0 1 12 20.6 Z" fill="currentColor"/>', $svg);

        // Only the right half moves: the lit half carries no class.
        $this->assertSame(1, substr_count($svg, 'class="td-wax"'));
        $this->assertSame(2, substr_count($svg, 'fill="currentColor"/>'));
    }

    public function test_it_waxes_from_half_to_full_and_wanes_back(): void
    {
        $frames = $this->phase();

        // In order: collapsed, full, collapsed. Scale, not rotate.
        $this->assertMatchesRegularExpression(
            '/0%.*scaleX\(0\).*48%.*scaleX\(1\).*100%.*scaleX\(0\)/s',
            $frames
        );
        $this->assertStringNotContainsString('rotate(', $frames);
    }

    public function test_the_waxing_half_grows_out_of_the_centre_line(): void
    {
        preg_match('/\.td-wax \{(.*?)\}/s', $this->icon(), $m);
        $this->assertNotEmpty($m);
        $rule = preg_replace('#/\*.*?\*/#s', '', $m[1]);

        $this->assertStringContainsString('transform-origin: 12px 12px', $rule);
        $this->assertStringContainsString('animation: td-phase 6s', $rule);
        $this->assertStringContainsString('infinite', $rule);
    }

    /** With the animation off, the icon must still read as a HALF moon, not a filled disc. */
    public function test_at_rest_and_under_reduced_motion_the_moon_is_half(): void
    {
        $svg = $this->icon();

        preg_match('/\.td-wax \{(.*?)\}/s', $svg, $m);
        $this->assertMatchesRegularExpression('/transform: scaleX\(0\);/', preg_replace('#/\*.*?\*/#s', '', $m[1]));

        $this->assertMatchesRegularExpression(
            '/prefers-reduced-motion: reduce\) \{ \.td-wax \{ animation: none; \} \}/',
            $svg
        );
    }

    public function test_the_old_flipping_coin_is_gone(): void
    {
        $svg = $this->icon();

        $this->assertStringNotContainsString('td-flip', $svg);
        $this->assertStringNotContainsString('td-half', $svg);
        $this->assertStringNotContainsString('rotate(180deg)', $svg);
    }

    public function test_it_sizes_from_the_call_site_and_keeps_its_name(): void
    {
        $svg = $this->icon();

        $this->assertMatchesRegularExpression('/<svg class="[^"]*\bh-5 w-5\b[^"]*"/', $svg);
        $this->assertStringContainsString('role="img" aria-label="Dark theme"', $svg);
    }
}
