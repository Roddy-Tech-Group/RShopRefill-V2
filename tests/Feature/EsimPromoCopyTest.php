<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The eSIM hero's third advert ran a World Cup stadium scene, and the
 * first-visit popup asked "Heading to the World Cup, or travelling soon?" with
 * that line written straight into the Blade. The tournament has passed.
 *
 * The card keeps its slot with copy that does not expire, and every string in
 * the popup is a setting now, so the next seasonal push is an admin edit rather
 * than a deploy.
 */
class EsimPromoCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_world_cup_copy_is_gone_from_the_esim_page(): void
    {
        $response = $this->get(route('shop.esims'))->assertOk();

        $response->assertDontSee('World Cup');
        $response->assertDontSee('kickoff');
        $response->assertDontSee('final whistle');
    }

    public function test_the_football_illustration_is_gone(): void
    {
        $response = $this->get(route('shop.esims'))->assertOk();

        // The stadium scene's animation classes are unique to it.
        $response->assertDontSee('e3-ball', escape: false);
        $response->assertDontSee('e3-tro', escape: false);
        $response->assertDontSee('football stadium', escape: false);
    }

    public function test_the_third_card_keeps_its_slot_with_evergreen_copy(): void
    {
        $response = $this->get(route('shop.esims'))->assertOk();

        // Still three adverts, not two.
        $response->assertSee('Feel the freedom of unlimited data');
        $response->assertSee('Stay connected the moment you land');
        $response->assertSee('Set up in minutes, before you even pack');

        // And the globe scene replaced the stadium.
        $response->assertSee('e4-orbit', escape: false);
    }

    public function test_the_migration_rewrote_the_retired_promo_rows(): void
    {
        $this->assertSame(
            'Set up in minutes, before you even pack',
            SiteSetting::get('esim.promo3_heading')
        );
        $this->assertStringNotContainsString(
            'World Cup',
            (string) SiteSetting::get('esim.promo3_text')
        );
    }

    /**
     * An admin who had already reworded the card must not have their copy
     * overwritten by a migration retiring somebody else's wording.
     */
    public function test_admin_edited_copy_survives_and_still_drives_the_page(): void
    {
        SiteSetting::put('esim.promo3_heading', 'Our own third headline', 'esim');
        SiteSetting::put('esim.promo3_text', 'Our own third paragraph.', 'esim');

        $this->get(route('shop.esims'))
            ->assertOk()
            ->assertSee('Our own third headline')
            ->assertSee('Our own third paragraph.');
    }

    // ────────────────────────────────────────────────────────────
    //  First-visit popup
    // ────────────────────────────────────────────────────────────

    /**
     * The first-visit popup left with the World Cup advert: the landing leads
     * with the catalogue, so its copy settings no longer reach the page.
     */
    public function test_the_popup_copy_no_longer_reaches_the_page(): void
    {
        SiteSetting::put('esim.tip_heading', 'Off somewhere nice?', 'esim');
        SiteSetting::put('esim.tip_text', 'Sort your data before you fly.', 'esim');
        SiteSetting::put('esim.tip_body', 'A custom body paragraph for the popup.', 'esim');

        $html = $this->get(route('shop.esims'))->assertOk()->getContent();

        $this->assertFalse(str_contains($html, 'Off somewhere nice?'), 'the popup heading is back');
        $this->assertFalse(str_contains($html, 'A custom body paragraph for the popup.'), 'the popup body is back');
    }

    public function test_the_popup_ships_with_seeded_evergreen_copy(): void
    {
        $this->assertSame('Where are you going?', SiteSetting::get('esim.tip_heading'));
        $this->assertStringNotContainsString(
            'World Cup',
            (string) SiteSetting::get('esim.tip_text')
        );

        // Seeded still, shown nowhere: the popup is gone.
        $html = $this->get(route('shop.esims'))->assertOk()->getContent();
        $this->assertFalse(str_contains($html, 'Where are you going?'), 'the popup is back');
    }
}
