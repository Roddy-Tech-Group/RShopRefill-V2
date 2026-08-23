<?php

namespace Database\Factories;

use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KycVerification>
 */
class KycVerificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'smile',
            'reference' => 'kyc_'.$this->faker->unique()->numerify('###').'_'.Str::lower(Str::random(12)),
            'product' => 'doc_verification',
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'result_code' => '1012',
            'decided_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Unable to validate ID - Not Found'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'result_code' => '1013',
            'result_text' => $reason,
            'decided_at' => now(),
        ]);
    }
}
