<?php

namespace Tests\Feature\Blog;

use Tests\TestCase;

/**
 * The blog post hero image follows the uploaded image's natural aspect ratio
 * (full width, auto height) instead of a fixed-height letterbox frame. The
 * "More from the blog" thumbnails stay uniform so the related grid reads clean.
 */
class BlogPostImageTest extends TestCase
{
    public function test_hero_image_is_fluid_and_related_thumbnails_stay_uniform(): void
    {
        $blade = file_get_contents(resource_path('views/shop/blog-post.blade.php'));

        // Hero: no fixed-height flat frame; image drives the height.
        $this->assertStringNotContainsString('height: 18rem;', $blade);
        $this->assertStringContainsString('class="h-auto w-full object-contain"', $blade);

        // Related-posts thumbnails keep their uniform card height.
        $this->assertStringContainsString('height: 10rem;', $blade);
    }
}
