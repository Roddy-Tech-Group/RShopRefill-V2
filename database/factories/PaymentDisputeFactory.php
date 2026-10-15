<?php

namespace Database\Factories;

use App\Domain\Risk\Enums\DisputeStatus;
use App\Models\PaymentDispute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentDispute>
 */
class PaymentDisputeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gateway' => 'flutterwave',
            'gateway_dispute_id' => (string) $this->faker->unique()->numberBetween(1000, 999999),
            'amount' => 25,
            'currency' => 'USD',
            'reason' => 'Fraud dispute',
            'stage' => 'new',
            'gateway_status' => 'initiated',
            'status' => DisputeStatus::Open,
            'due_at' => now()->addDays(3),
            'opened_at' => now(),
            'source' => 'webhook',
        ];
    }
}
