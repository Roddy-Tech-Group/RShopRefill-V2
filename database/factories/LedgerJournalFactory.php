<?php

namespace Database\Factories;

use App\Models\LedgerJournal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerJournal>
 */
class LedgerJournalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'description' => $this->faker->sentence(3),
            'currency' => 'NGN',
            'status' => 'settled',
            'idempotency_key' => $this->faker->uuid(),
        ];
    }
}
