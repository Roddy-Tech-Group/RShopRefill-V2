<?php

namespace Database\Factories;

use App\Models\HeroCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeroCard>
 */
class HeroCardFactory extends Factory
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
            'subtitle' => fake()->sentence(6),
            'image_path' => 'hero-cards/'.fake()->unique()->slug(2).'.png',
            'focal_x' => 50,
            'link_url' => '/gift-cards',
            'learn_url' => '/help',
            'buy_url' => null,
            'is_enabled' => true,
            'sort_order' => 10,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_enabled' => false]);
    }
}
