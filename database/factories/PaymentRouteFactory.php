<?php

namespace Database\Factories;

use App\Models\PaymentRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentRoute>
 */
class PaymentRouteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => 'ZAR',
            'method' => 'card',
            'is_offered' => true,
            'sort_order' => 10,
            'gateways' => ['flutterwave'],
            'note' => null,
        ];
    }

    public function withheld(string $note = 'Switched off for testing.'): static
    {
        return $this->state(fn () => ['is_offered' => false, 'note' => $note]);
    }
}
