<?php

namespace Database\Factories;

use App\Models\HeroSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroSection>
 */
class HeroSectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'subtitle' => fake()->sentence(8),
            'cta_label' => 'Shop now',
            'cta_url' => '/gift-cards',
            'media_path' => null,
            'media_type' => null,
            'is_builtin' => false,
            'is_enabled' => true,
            'sort_order' => 1,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }

    public function image(string $path = 'hero/sample.jpg'): static
    {
        return $this->state(fn () => ['media_path' => $path, 'media_type' => 'image']);
    }

    public function video(string $path = 'hero/sample.mp4'): static
    {
        return $this->state(fn () => ['media_path' => $path, 'media_type' => 'video']);
    }
}
