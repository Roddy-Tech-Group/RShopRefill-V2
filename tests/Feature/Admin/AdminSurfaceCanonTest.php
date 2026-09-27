<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One surface language for every admin page and sub-page.
 *
 * Cards are shadow-only (no ring, no border), list rows hover as a flat
 * neutral band (no radius, no ring, no blue dark tint of their own), every
 * table is an .admin-table so its header is the same pill, and radii are the
 * 12px house value. This scans every admin view rather than pinning pages one
 * at a time, because the drift kept coming back page by page.
 */
class AdminSurfaceCanonTest extends TestCase
{
    /** @return array<string, string> rel path => source */
    private function adminViews(): array
    {
        $views = [];
        foreach (['views/admin', 'views/livewire/admin', 'views/components/admin'] as $dir) {
            foreach (File::allFiles(resource_path($dir)) as $file) {
                if (str_ends_with($file->getFilename(), '-pdf.blade.php')) {
                    continue; // print templates carry their own inline CSS
                }
                $views[str_replace('\\', '/', substr($file->getPathname(), strlen(resource_path('views')) + 1))] = File::get($file->getPathname());
            }
        }
        $views['components/layouts/admin.blade.php'] = File::get(resource_path('views/components/layouts/admin.blade.php'));

        return $views;
    }

    /** @return list<array{tag: string, class: string, line: int}> */
    private function openingTags(string $src): array
    {
        preg_match_all('/<([a-zA-Z][\w.:-]*)(\s(?:"[^"]*"|\'[^\']*\'|[^>"\'])*)?>/', $src, $m, PREG_OFFSET_CAPTURE);
        $tags = [];
        foreach ($m[0] as $i => [$whole, $offset]) {
            $attrs = $m[2][$i][0] ?? '';
            if (! preg_match('/(^|\s)class="([^"]*)"/', $attrs, $c)) {
                continue;
            }
            $tags[] = ['tag' => strtolower($m[1][$i][0]), 'class' => $c[2], 'line' => substr_count(substr($src, 0, $offset), "\n") + 1];
        }

        return $tags;
    }

    private static function hasToken(string $class, string $pattern): bool
    {
        return (bool) preg_match('/(^|[\s\'"])(?:'.$pattern.')(?=[\s\'"]|$)/', $class);
    }

    public function test_cards_carry_no_ring_and_no_border(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $rel => $src) {
            foreach ($this->openingTags($src) as $t) {
                if (! in_array($t['tag'], ['div', 'section', 'form', 'article', 'li', 'nav', 'ul'], true)) {
                    continue;
                }
                $c = $t['class'];
                // Popovers keep a ring (they float over content); input groups and
                // icon tiles are controls, not cards.
                if (self::hasToken($c, 'absolute') || self::hasToken($c, 'fixed') || str_contains($c, 'focus-within:') || self::hasToken($c, 'border-(dashed|dotted)')) {
                    continue;
                }
                if (self::hasToken($c, 'h-(\d+|\[\d+px\])') && self::hasToken($c, 'w-(\d+|\[\d+px\])')) {
                    continue;
                }
                if (self::hasToken($c, 'overflow-hidden') && self::hasToken($c, 'flex') && ! self::hasToken($c, '(p|px|py)-[\d.]+')) {
                    continue;
                }
                $ringed = self::hasToken($c, 'ring-1') && self::hasToken($c, 'ring-(zinc|gray|black)-?[\w\/.\[\]]*');
                $bordered = self::hasToken($c, 'border') && self::hasToken($c, 'border-(zinc|gray|blue)-\d+(\/\d+)?') && ! self::hasToken($c, 'border-[trblxy]');
                if ($ringed || $bordered) {
                    $offenders[] = "{$rel}:{$t['line']} <{$t['tag']}>";
                }
            }
        }

        $this->assertSame([], $offenders, 'these surfaces still draw a ring or a full border');
    }

    public function test_list_rows_hover_flat_and_neutral(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $rel => $src) {
            foreach ($this->openingTags($src) as $t) {
                $c = $t['class'];
                if (! self::hasToken($c, 'hover:bg-(blue-(50|100)|zinc-(50|100)(\/\d+)?)')) {
                    continue;
                }
                $isRow = in_array($t['tag'], ['tr', 'li'], true) || self::hasToken($c, 'mx-3') || preg_match('/\w-body\b/', $c);
                if (preg_match('/(^|[\s\'"])(hover:ring-|dark:hover:ring-|hover:rounded-|hover:border-)/', $c)) {
                    $offenders[] = "{$rel}:{$t['line']} <{$t['tag']}> hover noise";
                }
                if ($isRow && (self::hasToken($c, 'rounded-[\w\/.\[\]]+') || self::hasToken($c, 'hover:bg-zinc-(50|100)(\/\d+)?') || self::hasToken($c, 'dark:hover:bg-[\w\/.\[\]]+'))) {
                    $offenders[] = "{$rel}:{$t['line']} <{$t['tag']}> row not flat/neutral";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_every_table_is_an_admin_table(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $rel => $src) {
            preg_match_all('/<table\b[^>]*>/', $src, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$tag, $offset]) {
                if (! str_contains($tag, 'admin-table')) {
                    $offenders[] = $rel.':'.(substr_count(substr($src, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'these tables miss the .admin-table header pill / row hover');
    }

    public function test_radii_are_the_house_12px(): void
    {
        $offenders = [];

        foreach ($this->adminViews() as $rel => $src) {
            if (preg_match_all('/rounded-(xl|2xl|lg|\[(10|14|16|20)px\])\b/', $src, $m)) {
                $offenders[] = $rel.' ('.implode(', ', array_unique($m[0])).')';
            }
        }

        $this->assertSame([], $offenders, 'off-scale radii; the house value is rounded-[12px]');
    }
}
