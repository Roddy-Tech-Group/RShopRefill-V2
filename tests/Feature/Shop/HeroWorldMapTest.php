<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The built-in hero sits over the dotted world map.
 *
 * The map file paints its dots in a dark navy, so the hero uses it as a mask
 * and takes the colour from its own theme. These render the component directly
 * so the admin's hero-section settings cannot decide whether it shows up.
 */
class HeroWorldMapTest extends TestCase
{
    use RefreshDatabase;

    private const MAP = 'assets/dotted-world-map.svg';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_backdrop_masks_the_world_map(): void
    {
        $html = (string) $this->blade('<x-home.hero />');

        $this->assertFileExists(public_path(self::MAP));
        $this->assertStringContainsString('class="roddy-world-map"', $html);
        $this->assertStringContainsString(
            "mask: url('".asset(self::MAP).'?v='.filemtime(public_path(self::MAP))."')",
            $html,
            'the map must be versioned so a replaced file is not served stale'
        );
    }

    /**
     * The map runs edge to edge: no side fade, and the layer overhangs the hero
     * by the blank margin the file keeps around its dots, grown by the leftward
     * shift so the right edge still meets the page. The offsets are read from
     * the file itself, so a replaced map with different margins fails here
     * instead of drifting off the page edges.
     */
    public function test_the_map_runs_the_full_width_of_the_page(): void
    {
        $leftwardShift = 0.04;
        $html = (string) $this->blade('<x-home.hero />');
        $svg = file_get_contents(public_path(self::MAP));

        preg_match('/viewBox="0 0 ([\d.]+) /', $svg, $viewBox);
        preg_match_all('/<circle cx="([\d.]+)" cy="[\d.]+" r="([\d.]+)"/', $svg, $dots);

        $leftEdges = array_map(fn (string $cx, string $r): float => (float) $cx - (float) $r, $dots[1], $dots[2]);
        $rightEdges = array_map(fn (string $cx, string $r): float => (float) $cx + (float) $r, $dots[1], $dots[2]);
        $dotSpan = max($rightEdges) - min($leftEdges);
        $scale = 1 + $leftwardShift;

        $leftOverhang = number_format(($leftwardShift + min($leftEdges) / $dotSpan * $scale) * 100, 2);
        $rightOverhang = number_format(((float) $viewBox[1] - max($rightEdges)) / $dotSpan * $scale * 100, 2);

        $this->assertStringNotContainsString('linear-gradient(to right', $html, 'a side fade pulls the map in from the page edges');
        $this->assertStringContainsString("left: -{$leftOverhang}%;", $html);
        $this->assertStringContainsString("right: -{$rightOverhang}%;", $html);
    }

    /** The dots show at their full theme colour, with nothing fading the layer. */
    public function test_the_map_is_fully_opaque(): void
    {
        $html = (string) $this->blade('<x-home.hero />');

        $this->assertMatchesRegularExpression('/\.roddy-world-map \{[^}]*\}/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.roddy-world-map \{[^}]*opacity:/', $html);
    }

    /** The old flat dot grid is gone, not layered under the map. */
    public function test_the_plain_dot_grid_is_retired(): void
    {
        $html = (string) $this->blade('<x-home.hero />');

        $this->assertStringNotContainsString('background-size: 38px 38px', $html);
    }

    /** Admin-uploaded hero media replaces the backdrop outright. */
    public function test_uploaded_media_replaces_the_map(): void
    {
        $html = (string) $this->blade('<x-home.hero media-path="hero.jpg" media-type="image" />');

        $this->assertStringContainsString(asset('assets/hero.jpg'), $html);
        $this->assertStringNotContainsString('class="roddy-world-map"', $html);
    }
}
