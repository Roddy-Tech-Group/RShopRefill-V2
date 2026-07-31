<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true).' account',
            'type' => 'liability',
            'currency' => 'NGN',
        ];
    }

    public function asset(): static
    {
        return $this->state(fn (): array => ['type' => 'asset']);
    }

    public function liability(): static
    {
        return $this->state(fn (): array => ['type' => 'liability']);
    }
}
