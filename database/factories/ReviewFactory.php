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

    /**
     * An answer to the experience prompt: pending, flagged as a customer
     * submission, carrying the 1-10 score with the rating halved for the stars.
     */
    public function fromPrompt(int $score = 9, ?string $category = null): static
    {
        return $this->state(fn (): array => [
            'score' => $score,
            'rating' => $score / 2,
            'category' => $category,
            'is_published' => false,
            'is_customer_submitted' => true,
            'source' => 'RshopRefill',
        ]);
    }

    /** Printed without the author's name on customer-facing surfaces. */
    public function anonymous(): static
    {
        return $this->state(fn (): array => ['is_anonymous' => true]);
    }

    /** A score with nothing said: counts toward ratings, never reaches a card. */
    public function scoreOnly(): static
    {
        return $this->state(fn (): array => ['body' => null]);
    }
}
