<?php

namespace Tests\Feature\Shop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Flags and the decorative SVGs are not allowed to get heavy again.
 *
 * Flags come from an external CDN, one request per flag, and the preferences
 * modal alone lists a flag per country. Sized so a row keeps its shape before
 * the picture lands, decoded off the main thread, and fetched lazily wherever
 * the flag is not already on screen: a lazy image inside a closed modal is not
 * requested at all until the modal opens.
 *
 * The dotted world map is the other half. It is the hero's backdrop and the
 * dashboard's wallpaper, and it is drawn as one circle per dot, so anything
 * repeated on a circle is repeated two thousand times.
 */
class FlagAndSvgLoadingTest extends TestCase
{
    /** Every flag image in the codebase, as a [file, tag] pair. */
    public static function flagTags(): array
    {
        $tags = [];
        foreach (File::allFiles(resource_path('views')) as $view) {
            if (! preg_match_all('/<img\b[^>]*flagcdn[^>]*>/s', $view->getContents(), $m)) {
                continue;
            }
            foreach ($m[0] as $i => $tag) {
                $tags[$view->getRelativePathname().' #'.($i + 1)] = [$view->getRelativePathname(), $tag];
            }
        }

        return $tags;
    }

    public function test_every_flag_states_its_size_and_decodes_off_the_main_thread(): void
    {
        $tags = self::flagTags();
        $this->assertNotEmpty($tags, 'No flag images found - has the CDN host changed?');

        foreach ($tags as $where => [$file, $tag]) {
            $flat = preg_replace('/\s+/', ' ', $tag);

            $this->assertMatchesRegularExpression('/\bwidth="\d+"/', $flat, "{$where} does not say how wide it is");
            $this->assertMatchesRegularExpression('/\bheight="\d+"/', $flat, "{$where} does not say how tall it is");
            $this->assertStringContainsString('decoding="async"', $flat, "{$where} decodes on the main thread");
        }
    }

    /**
     * The two region pills are in the nav on arrival, so they load at once.
     * Everything else waits: the preferences modal is closed, the footer and
     * the profile page are below the fold, the admin roaming list is inside a
     * drawer. The country list is the reason this matters - it renders a flag
     * per country, and eager they all fire the moment any page loads.
     */
    public function test_only_the_nav_pills_load_their_flag_up_front(): void
    {
        $eager = [];
        foreach (self::flagTags() as $where => [$file, $tag]) {
            if (! str_contains($tag, 'loading="lazy"')) {
                $eager[] = str_replace('\\', '/', $file);
            }
        }

        sort($eager);
        $this->assertSame([
            'components/layouts/dashboard.blade.php',
            'components/nav/region-pill.blade.php',
        ], $eager, 'a flag outside the nav pills is being fetched before it is needed');
    }

    public function test_the_dotted_world_map_says_each_thing_once(): void
    {
        $path = public_path('assets/dotted-world-map.svg');
        $svg = File::get($path);

        // One fill for the whole map, on the root, inherited by every dot.
        $this->assertSame(1, substr_count($svg, '#0c1337'), 'the dot colour is repeated per circle');
        $this->assertStringNotContainsString('opacity="1"', $svg, 'a fully opaque dot does not need to say so');
        $this->assertDoesNotMatchRegularExpression('/c[xy]="-?\d+\.\d*0"/', $svg, 'trailing zeros in the coordinates');

        // The radius stays on every circle: fill is inherited, r is a geometry
        // property and is not, so hoisting it would collapse every dot to
        // nothing and the map would disappear.
        $dots = substr_count($svg, '<circle');
        $this->assertSame(2025, $dots, 'the map lost or gained dots');
        $this->assertSame($dots, substr_count($svg, 'r="3"'), 'every dot needs its own radius');

        // A budget with room to redraw the map, but not to bloat it back.
        $this->assertLessThan(90 * 1024, filesize($path), 'the world map is heavy again');
    }
}
