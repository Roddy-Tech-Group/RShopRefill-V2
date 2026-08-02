<?php

namespace Database\Factories;

use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'initials' => Str::upper(Str::substr($name, 0, 2)),
            'author_name' => $name,
            'body' => fake()->sentence(15),
            'rating' => fake()->numberBetween(4, 5),
            // Our own collector is the default: those are the only reviews the
            // storefront renders (see Review::scopeOwnCollected).
            'source' => 'RshopRefills',
            'reviewed_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A review attributed to an external platform. Kept out of every customer
     * facing surface - those platforms display their own reviews themselves.
     */
    public function external(string $platform = 'Trustpilot'): static
    {
        return $this->state(fn (): array => ['source' => $platform]);
    }
}
