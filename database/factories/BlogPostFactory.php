<?php

namespace Database\Factories;

use App\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BlogPost>
 */
class BlogPostFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(6);

        return [
            'slug' => Str::slug($title),
            'category' => fake()->randomElement(['Guides', 'Crypto', 'Travel', 'Security', 'Product']),
            'title' => $title,
            'excerpt' => fake()->sentence(12),
            'image' => 'hero gift.webp',
            'body' => [fake()->paragraph(3), fake()->paragraph(3)],
            'author' => 'RshopRefills Team',
            'author_avatar' => null,
            'video_urls' => [],
            'read_time' => fake()->numberBetween(2, 6).' min read',
            'published_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
