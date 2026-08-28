<?php

namespace Tests\Feature\Localization;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\BlogPost;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The admin panel where database content gets its per-language copy.
 *
 * Editors work field by field against the original text. A blank box means
 * "use the original", which is what makes a half-finished translation safe to
 * save - the site falls back rather than blanking.
 */
class TranslationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_the_page_renders_for_an_admin(): void
    {
        BlogPost::factory()->create(['title' => 'How gift cards work']);

        $this->actingAs($this->admin(), 'admin')
            ->withoutVite()
            ->get(route('admin.content.translations'))
            ->assertOk()
            ->assertSee('Translations')
            ->assertSee('How gift cards work');
    }

    /** Only languages that are not the source language can be translated into. */
    public function test_the_default_locale_is_not_offered_as_a_target(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $component = Volt::test('admin.content.translations');
        $targets = $component->instance()->targetLocales();

        $this->assertArrayNotHasKey(config('localization.default', 'en'), $targets);
        $this->assertArrayHasKey('fr', $targets);
    }

    public function test_saving_copy_stores_it_against_the_record(): void
    {
        $post = BlogPost::factory()->create([
            'title' => 'How gift cards work',
            'excerpt' => 'A short primer.',
        ]);

        $this->actingAs($this->admin(), 'admin');

        Volt::test('admin.content.translations')
            ->set('model', 'BlogPost')
            ->set('locale', 'fr')
            ->call('edit', $post->id)
            ->set('draft.title', 'Comment fonctionnent les cartes cadeaux')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('translations', [
            'translatable_type' => BlogPost::class,
            'translatable_id' => $post->id,
            'locale' => 'fr',
            'field' => 'title',
            'value' => 'Comment fonctionnent les cartes cadeaux',
        ]);
    }

    /** A blank box is "use the original", so it must not store an empty row. */
    public function test_a_blank_field_stores_nothing(): void
    {
        $post = BlogPost::factory()->create(['title' => 'How gift cards work']);

        $this->actingAs($this->admin(), 'admin');

        Volt::test('admin.content.translations')
            ->set('model', 'BlogPost')
            ->set('locale', 'fr')
            ->call('edit', $post->id)
            ->set('draft.title', '   ')
            ->call('save');

        $this->assertDatabaseCount('translations', 0);
    }

    /** Editing seeds the boxes with whatever is already translated. */
    public function test_opening_a_record_loads_its_existing_copy(): void
    {
        $post = BlogPost::factory()->create(['title' => 'How gift cards work']);
        $post->setTranslation('title', 'fr', 'Titre déjà traduit');

        $this->actingAs($this->admin(), 'admin');

        Volt::test('admin.content.translations')
            ->set('model', 'BlogPost')
            ->set('locale', 'fr')
            ->call('edit', $post->id)
            ->assertSet('draft.title', 'Titre déjà traduit');
    }

    /** Saving twice corrects the copy rather than stacking rows. */
    public function test_resaving_updates_in_place(): void
    {
        $post = BlogPost::factory()->create(['title' => 'How gift cards work']);

        $this->actingAs($this->admin(), 'admin');

        foreach (['Premier titre', 'Titre corrigé'] as $value) {
            Volt::test('admin.content.translations')
                ->set('model', 'BlogPost')
                ->set('locale', 'fr')
                ->call('edit', $post->id)
                ->set('draft.title', $value)
                ->call('save');
        }

        $this->assertSame(1, Translation::count());
        $this->assertSame('Titre corrigé', Translation::first()->value);
    }

    /**
     * 404, not a redirect to a login form: AdminAuth aborts rather than
     * bouncing, so the admin area never confirms its own existence to a
     * stranger.
     */
    public function test_a_guest_cannot_reach_the_page(): void
    {
        $this->withoutVite()
            ->get(route('admin.content.translations'))
            ->assertNotFound();
    }
}
