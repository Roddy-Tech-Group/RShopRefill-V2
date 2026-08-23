<?php

namespace Database\Factories;

use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskAssessment>
 */
class RiskAssessmentFactory extends Factory
{
    protected $model = RiskAssessment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'context' => 'order',
            'score' => 60,
            'band' => 'HIGH',
            'outcome' => 'review',
            'enforced' => true,
            'ip_address' => fake()->ipv4(),
            'device_uuid' => (string) fake()->uuid(),
            'amount_usd' => 120.00,
            'currency' => 'USD',
            'policy_version' => 'test',
        ];
    }
}
