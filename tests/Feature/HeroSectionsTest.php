<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\HeroSection;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\TestCase;

class HeroSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function adminLogin(): self
    {
        $this->seed(AdminSeeder::class);
        $this->actingAs(Admin::first(), 'admin');

        return $this;
    }

    /** Delete a section's uploaded file so test runs never litter public/assets/hero/. */
    protected function cleanUpMedia(?HeroSection $section): void
    {
        if ($section?->media_path) {
            File::delete(public_path('assets/'.$section->media_path));
        }
    }

    // ── Storefront ──────────────────────────────────────────────

    public function test_homepage_renders_the_default_hero(): void
    {
        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('All in One Place');
    }

    public function test_hero_scrolls_with_the_page_instead_of_pinning(): void
    {
        // The sticky pin wrapper was removed (July 2026): the hero must render
        // in normal flow, followed by the opaque content wrapper. Home pins its
        // own #ffffff rather than the shared bg-[#eff6ff] page tone now.
        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertDontSee('data-hero-stage', false)
            ->assertDontSee('class="sticky z-0"', false)
            ->assertSeeInOrder(['All in One Place', 'relative z-10 bg-[#ffffff]'], false);
    }

    public function test_homepage_hides_the_hero_when_all_sections_are_off(): void
    {
        HeroSection::query()->update(['is_enabled' => false]);
        HeroSection::bustCache();

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertDontSee('All in One Place');
    }

    public function test_enabled_custom_section_renders_alongside_the_default_hero(): void
    {
        HeroSection::factory()->create(['title' => 'Summer Giftcard Deals']);

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertSee('All in One Place')
            ->assertSee('Summer Giftcard Deals');
    }

    public function test_disabled_custom_section_does_not_render(): void
    {
        HeroSection::factory()->disabled()->create(['title' => 'Hidden Promo Slide']);

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertDontSee('Hidden Promo Slide');
    }

    public function test_hero_read_failure_falls_back_to_the_default_hero(): void
    {
        // Simulate the table missing mid-deploy (or a DB blip). The read must
        // not throw; it degrades to the built-in designed hero instead so the
        // homepage keeps rendering.
        Cache::shouldReceive('remember')
            ->once()
            ->andThrow(new \RuntimeException('database unavailable'));

        $sections = HeroSection::enabledCached();

        $this->assertCount(1, $sections);
        $this->assertTrue($sections->first()->is_builtin);
        $this->assertNull($sections->first()->media_path);
    }

    public function test_custom_section_can_replace_a_disabled_default_hero(): void
    {
        HeroSection::where('is_builtin', true)->update(['is_enabled' => false]);
        HeroSection::factory()->image()->create(['title' => 'Solo Promo Hero']);
        HeroSection::bustCache();

        $this->withoutVite()->get('/')
            ->assertOk()
            ->assertDontSee('All in One Place')
            ->assertSee('Solo Promo Hero');
    }

    // ── Admin access ────────────────────────────────────────────

    public function test_guest_is_redirected_from_the_hero_sections_admin(): void
    {
        $this->get(route('admin.content.hero-sections'))
            ->assertNotFound();
    }

    public function test_admin_page_lists_the_default_hero(): void
    {
        $this->withoutVite()->adminLogin();

        $this->get(route('admin.content.hero-sections'))
            ->assertOk()
            ->assertSee('Hero Sections')
            ->assertSee('Default hero');
    }

    // ── Admin CRUD ──────────────────────────────────────────────

    public function test_admin_can_create_a_section_with_an_image(): void
    {
        $this->adminLogin();

        Volt::test('admin.content.hero-sections')
            ->call('newSection')
            ->set('title', 'Black Friday')
            ->set('mediaUpload', UploadedFile::fake()->create('promo.jpg', 512, 'image/jpeg'))
            ->call('save')
            ->assertHasNoErrors();

        $section = HeroSection::where('title', 'Black Friday')->first();
        $this->assertNotNull($section);
        $this->assertSame('image', $section->media_type);
        $this->assertStringStartsWith('hero/', $section->media_path);
        $this->assertFileExists(public_path('assets/'.$section->media_path));

        $this->cleanUpMedia($section);
    }

    public function test_admin_can_upload_a_video_to_the_default_hero(): void
    {
        $this->adminLogin();
        $builtin = HeroSection::where('is_builtin', true)->firstOrFail();

        Volt::test('admin.content.hero-sections')
            ->call('edit', $builtin->id)
            ->set('mediaUpload', UploadedFile::fake()->create('backdrop.mp4', 2048, 'video/mp4'))
            ->call('save')
            ->assertHasNoErrors();

        $builtin->refresh();
        $this->assertSame('video', $builtin->media_type);
        $this->assertStringStartsWith('hero/', $builtin->media_path);

        $this->cleanUpMedia($builtin);
    }

    public function test_editing_the_default_hero_never_touches_its_designed_copy(): void
    {
        $this->adminLogin();
        $builtin = HeroSection::where('is_builtin', true)->firstOrFail();

        Volt::test('admin.content.hero-sections')
            ->call('edit', $builtin->id)
            ->set('title', 'Sneaky new headline')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Default hero', $builtin->refresh()->title);
    }

    public function test_admin_can_toggle_the_default_hero_off_and_on(): void
    {
        $this->adminLogin();
        $builtin = HeroSection::where('is_builtin', true)->firstOrFail();

        Volt::test('admin.content.hero-sections')->call('toggle', $builtin->id);
        $this->assertFalse($builtin->refresh()->is_enabled);

        Volt::test('admin.content.hero-sections')->call('toggle', $builtin->id);
        $this->assertTrue($builtin->refresh()->is_enabled);
    }

    public function test_admin_cannot_delete_the_default_hero(): void
    {
        $this->adminLogin();
        $builtin = HeroSection::where('is_builtin', true)->firstOrFail();

        Volt::test('admin.content.hero-sections')->call('delete', $builtin->id);

        $this->assertDatabaseHas('hero_sections', ['id' => $builtin->id]);
    }

    public function test_admin_can_delete_a_custom_section(): void
    {
        $this->adminLogin();
        $section = HeroSection::factory()->create();

        Volt::test('admin.content.hero-sections')->call('delete', $section->id);

        $this->assertDatabaseMissing('hero_sections', ['id' => $section->id]);
    }

    public function test_custom_section_requires_a_headline_or_media(): void
    {
        $this->adminLogin();

        Volt::test('admin.content.hero-sections')
            ->call('newSection')
            ->call('save')
            ->assertHasErrors('title');

        $this->assertSame(1, HeroSection::count());
    }

    public function test_removing_media_on_save_clears_it_from_the_section(): void
    {
        $this->adminLogin();
        $section = HeroSection::factory()->image()->create();

        Volt::test('admin.content.hero-sections')
            ->call('edit', $section->id)
            ->call('removeMedia')
            ->call('save')
            ->assertHasNoErrors();

        $section->refresh();
        $this->assertNull($section->media_path);
        $this->assertNull($section->media_type);
    }
}
