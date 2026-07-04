<?php

namespace Tests\Feature\Blog;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Author photo in the byline + pasted video links: YouTube/Vimeo embed as players,
 * anything else falls back to a "Watch video" link.
 */
class BlogPostMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_youtube_and_vimeo_links_convert_to_embed_urls(): void
    {
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', BlogPost::videoEmbedUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ', BlogPost::videoEmbedUrl('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('https://player.vimeo.com/video/123456789', BlogPost::videoEmbedUrl('https://vimeo.com/123456789'));
        $this->assertNull(BlogPost::videoEmbedUrl('https://example.com/not-a-video'));
    }

    public function test_post_page_shows_author_avatar_and_embedded_video(): void
    {
        $post = BlogPost::factory()->create([
            'author' => 'Jane Doe',
            'author_avatar' => 'blog/jane-avatar.jpg',
            'video_urls' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'is_published' => true,
        ]);

        $this->get(route('shop.blog.show', $post->slug))
            ->assertOk()
            ->assertSee('jane-avatar', false)                                   // avatar img src
            ->assertSee('https://www.youtube.com/embed/dQw4w9WgXcQ', false);     // iframe embed src
    }

    public function test_non_embeddable_video_renders_as_a_link(): void
    {
        $post = BlogPost::factory()->create([
            'video_urls' => ['https://example.com/my-video.mp4'],
            'is_published' => true,
        ]);

        $this->get(route('shop.blog.show', $post->slug))
            ->assertOk()
            ->assertSee('Watch video')
            ->assertSee('https://example.com/my-video.mp4', false);
    }
}
