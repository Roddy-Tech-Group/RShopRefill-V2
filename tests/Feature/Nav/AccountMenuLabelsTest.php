<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The storefront account menu's rewards row is labelled "Rcoin Rewards", not
 * "Redeem". The key was renamed with it: a key called `redeem` carrying a
 * rewards label would mislead the next person to touch it.
 */
class AccountMenuLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** The signed-in home page, in the given locale. */
    private function home(string $locale = 'en'): string
    {
        return $this->actingAs(User::factory()->create(['locale' => $locale]))
            ->withoutVite()
            ->get('/')
            ->assertOk()
            ->getContent();
    }

    /** The text of every menu item in the account dropdown (the only menu on the page with the gift row). */
    private function menuItemLabels(string $html): array
    {
        preg_match_all('/<a[^>]*role="menuitem"[^>]*>(.*?)<\/a>/s', $html, $m);
        $this->assertNotEmpty($m[1], 'No menu items rendered.');

        return array_map(fn ($inner) => trim(strip_tags($inner)), $m[1]);
    }

    public function test_the_rewards_row_says_rcoin_rewards(): void
    {
        $labels = $this->menuItemLabels($this->home());

        $this->assertContains('Rcoin Rewards', $labels);
        $this->assertNotContains('Redeem', $labels);
    }

    public function test_the_key_was_renamed_with_the_label_in_every_locale(): void
    {
        foreach (['en', 'fr', 'es', 'de', 'pt', 'it', 'ar', 'sw', 'ha', 'zh'] as $locale) {
            $lines = include lang_path("{$locale}/navigation.php");

            $this->assertArrayHasKey('rcoin_rewards', $lines, "{$locale} is missing rcoin_rewards.");
            $this->assertArrayNotHasKey('redeem', $lines, "{$locale} still carries the old redeem key.");

            // The coin is the operator's own name and is never translated.
            $this->assertStringContainsString('Rcoin', $lines['rcoin_rewards'], "{$locale} translated the coin's name.");
        }
    }

    public function test_the_row_is_translated_not_left_in_english(): void
    {
        $labels = $this->menuItemLabels($this->home('fr'));

        $this->assertContains('Récompenses Rcoin', $labels);
        $this->assertNotContains('Rcoin Rewards', $labels);
    }

    public function test_nothing_still_reads_the_old_key(): void
    {
        $views = collect(File::allFiles(resource_path('views')))
            ->map(fn ($f) => $f->getContents())
            ->implode("\n");

        $this->assertStringNotContainsString("navigation.redeem'", $views);
    }
}
