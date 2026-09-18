<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nav's account avatar is a circle, and without a photo it is the same
 * plain user glyph the dashboard's avatar button falls back to.
 *
 * The rest of the bar is the project's 12px-square language, and the avatar
 * wore a 10px square with it. But this control is a person, not a panel - a
 * face reads as a face at a circle and as a button at a square, and every
 * other product puts the two in different shapes for exactly that reason.
 *
 * The fallback used to be a coloured initials tile here while the dashboard
 * drew an icon, so the same account looked like two people across the two
 * bars. Both now fall back to flux:icon.user.
 */
class AccountAvatarShapeTest extends TestCase
{
    use RefreshDatabase;

    private function home(User $user): string
    {
        return $this->actingAs($user)
            ->withoutVite()
            ->get('/')
            ->assertOk()
            ->getContent();
    }

    /** The avatar button's opening tag, cut out of the page. */
    private function avatarButtonTag(string $html): string
    {
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Your account"/', $html);

        preg_match('/<button[^>]*aria-label="Your account"[^>]*>/', $html, $m);

        return $m[0];
    }

    /** The whole avatar button, open tag through its close. */
    private function avatarButton(string $html): string
    {
        preg_match('/<button[^>]*aria-label="Your account"[^>]*>.*?<\/button>/s', $html, $m);
        $this->assertNotEmpty($m, 'The account avatar button did not render.');

        return $m[0];
    }

    public function test_the_account_avatar_is_a_circle(): void
    {
        $tag = $this->avatarButtonTag($this->home(User::factory()->create()));

        $this->assertStringContainsString('rounded-full', $tag);
        $this->assertStringNotContainsString('rounded-[10px]', $tag);
    }

    public function test_without_a_photo_the_avatar_is_the_dashboards_user_glyph(): void
    {
        $button = $this->avatarButton($this->home(User::factory()->create(['avatar_url' => null])));

        // The Flux icon, at the dashboard's size, stroked in the button's colour.
        $this->assertMatchesRegularExpression('/<svg[^>]*class="[^"]*\bh-5 w-5\b[^"]*"[^>]*data-flux-icon/', $button);
        $this->assertStringContainsString('stroke="currentColor"', $button);

        // No initials tile, and no image at all: the glyph is inline.
        $this->assertStringNotContainsString('data:image/svg+xml', $button);
        $this->assertStringNotContainsString('<img', $button);
    }

    public function test_with_a_photo_the_avatar_is_the_photo(): void
    {
        $user = User::factory()->create(['avatar_url' => '/storage/avatars/real-photo.jpg']);
        $button = $this->avatarButton($this->home($user));

        $this->assertStringContainsString('<img src="/storage/avatars/real-photo.jpg"', $button);
        $this->assertStringContainsString('h-full w-full object-cover', $button);
        $this->assertStringNotContainsString('data-flux-icon', $button);
    }

    /** The glyph needs a colour of its own: the button paints no text colour otherwise. */
    public function test_the_glyph_has_a_colour_in_both_themes(): void
    {
        $tag = $this->avatarButtonTag($this->home(User::factory()->create(['avatar_url' => null])));

        $this->assertStringContainsString('text-zinc-700', $tag);
        $this->assertStringContainsString('dark:text-zinc-200', $tag);
    }

    /**
     * The container clips whatever it holds. A real photo is square on disk and
     * the initials generator (still used by the admin views and the referrer
     * stack) emits a plain square; nothing about the nav's shape is baked into
     * either, so the same asset drops into a circle here and a rounded square
     * anywhere else.
     */
    public function test_the_container_is_what_clips_the_avatar(): void
    {
        $this->assertStringContainsString('overflow-hidden', $this->avatarButtonTag($this->home(User::factory()->create())));

        $svg = base64_decode(str_replace('data:image/svg+xml;base64,', '', User::factory()->create()->initialsAvatar()));
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsString('<circle', $svg);
    }
}
