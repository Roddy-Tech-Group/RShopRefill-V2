<?php

namespace Tests\Feature\Nav;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nav's account avatar is a circle.
 *
 * The rest of the bar is the project's 12px-square language, and the avatar
 * wore a 10px square with it. But this control is a person, not a panel - a
 * face reads as a face at a circle and as a button at a square, and every
 * other product puts the two in different shapes for exactly that reason.
 */
class AccountAvatarShapeTest extends TestCase
{
    use RefreshDatabase;

    /** The avatar button, cut out of the page. */
    private function avatarButton(string $html): string
    {
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Your account"/', $html);

        preg_match('/<button[^>]*aria-label="Your account"[^>]*>/', $html, $m);

        return $m[0];
    }

    public function test_the_account_avatar_is_a_circle(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/')
            ->assertOk()
            ->getContent();

        $button = $this->avatarButton($html);

        $this->assertStringContainsString('rounded-full', $button);
        $this->assertStringNotContainsString('rounded-[10px]', $button);
    }

    /**
     * The initials fallback is a square SVG on purpose - the shape comes from
     * the container's overflow-hidden, so the same asset can be dropped into a
     * circle here and a rounded square anywhere else without regenerating it.
     */
    public function test_the_container_is_what_clips_the_avatar(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->withoutVite()
            ->get('/')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('overflow-hidden', $this->avatarButton($html));

        // The generator still emits a plain square; nothing about the nav's
        // shape is baked into it.
        $svg = base64_decode(str_replace('data:image/svg+xml;base64,', '', User::factory()->create()->initialsAvatar()));
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringNotContainsString('<circle', $svg);
    }
}
