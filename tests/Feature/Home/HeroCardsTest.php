<?php

namespace Tests\Feature\Home;

use App\Models\Admin;
use App\Models\HeroCard;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Hero highlight cards: Apple-style tiles right under the hero, CMS-managed
 * via admin > Content > Hero Cards (hero_cards table, seeded with the three
 * original cards). A card renders only when its image file exists, so the
 * section is invisible until the assets are in place.
 */
class HeroCardsTest extends TestCase
{
    use RefreshDatabase;

    /** Image files the seeded cards point at (relative to public/assets/). */
    private const SEEDED_IMAGES = [
        'Hero Section 3.png',
        'Hero 2 Shop.png',
        'Hero section 4.png',
    ];

    protected function adminLogin(): self
    {
        $this->seed(AdminSeeder::class);
        $this->actingAs(Admin::first(), 'admin');

        return $this;
    }

    /** Create stub files for missing seeded images; returns paths created. */
    private function ensureSeededImages(): array
    {
        $created = [];
        foreach (self::SEEDED_IMAGES as $image) {
            $path = public_path('assets/'.$image);
            if (! file_exists($path)) {
                File::put($path, 'stub');
                $created[] = $path;
            }
        }

        return $created;
    }

    // ── Storefront ──────────────────────────────────────────────

    public function test_section_is_hidden_while_the_images_are_missing(): void
    {
        if (collect(self::SEEDED_IMAGES)->some(fn ($image) => file_exists(public_path('assets/'.$image)))) {
            $this->markTestSkipped('Hero card images are present locally.');
        }

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertDontSee('Hero highlights')
            ->assertDontSee('Buy Apple Gift Cards');
    }

    public function test_seeded_cards_render_with_their_copy_once_the_images_exist(): void
    {
        $created = $this->ensureSeededImages();

        try {
            $this->withoutVite()->get('/')
                ->assertOk()
                ->assertSee('Hero highlights')
                ->assertSee('data-hero-cards', false)
                ->assertSee('Buy Apple Gift Cards')
                ->assertSee('Discover Global eSIM')
                ->assertSee('dashboard')
                ->assertSee('Learn more')
                ->assertSee('Buy now');
        } finally {
            foreach ($created as $path) {
                File::delete($path);
            }
        }
    }

    public function test_disabled_card_and_card_with_missing_image_do_not_render(): void
    {
        $created = $this->ensureSeededImages();
        HeroCard::factory()->disabled()->create(['title' => 'Disabled Promo Card']);
        HeroCard::factory()->create(['title' => 'Ghost Card', 'image_path' => 'hero-cards/does-not-exist.png']);

        try {
            $this->withoutVite()->get('/')
                ->assertOk()
                ->assertDontSee('Disabled Promo Card')
                ->assertDontSee('Ghost Card');
        } finally {
            foreach ($created as $path) {
                File::delete($path);
            }
        }
    }

    // ── Admin ───────────────────────────────────────────────────

    public function test_guest_is_redirected_from_the_hero_cards_admin(): void
    {
        $this->get(route('admin.content.hero-cards'))
            ->assertNotFound();
    }

    public function test_admin_page_lists_the_seeded_cards(): void
    {
        $this->withoutVite()->adminLogin();

        $this->get(route('admin.content.hero-cards'))
            ->assertOk()
            ->assertSee('Hero Cards')
            ->assertSee('Buy Apple Gift Cards')
            ->assertSee('Discover Global eSIM');
    }

    public function test_admin_can_create_a_card_with_an_image(): void
    {
        $this->adminLogin();

        Volt::test('admin.content.hero-cards')
            ->call('newCard')
            ->set('title', 'Holiday Top-up Bonus')
            ->set('subtitle', 'Extra data on every recharge.')
            ->set('linkUrl', '/topups')
            ->set('buyUrl', '/topups')
            ->set('imageUpload', UploadedFile::fake()->create('bonus.png', 512, 'image/png'))
            ->call('save')
            ->assertHasNoErrors();

        $card = HeroCard::where('title', 'Holiday Top-up Bonus')->first();
        $this->assertNotNull($card);
        $this->assertStringStartsWith('hero-cards/', $card->image_path);
        $this->assertFileExists(public_path('assets/'.$card->image_path));

        File::delete(public_path('assets/'.$card->image_path));
    }

    public function test_new_card_requires_an_image(): void
    {
        $this->adminLogin();

        Volt::test('admin.content.hero-cards')
            ->call('newCard')
            ->set('title', 'No Image Card')
            ->call('save')
            ->assertHasErrors('imageUpload');

        $this->assertDatabaseMissing('hero_cards', ['title' => 'No Image Card']);
    }

    public function test_admin_can_toggle_and_delete_a_card(): void
    {
        $this->adminLogin();
        $card = HeroCard::factory()->create();

        Volt::test('admin.content.hero-cards')->call('toggle', $card->id);
        $this->assertFalse($card->refresh()->is_enabled);

        Volt::test('admin.content.hero-cards')->call('delete', $card->id);
        $this->assertDatabaseMissing('hero_cards', ['id' => $card->id]);
    }
}
