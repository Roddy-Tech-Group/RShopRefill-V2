<?php

namespace Database\Factories;

use App\Domain\Risk\Enums\RiskReviewStatus;
use App\Models\RiskAssessment;
use App\Models\RiskReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskReview>
 */
class RiskReviewFactory extends Factory
{
    protected $model = RiskReview::class;

    public function definition(): array
    {
        $user = User::factory();

        return [
            'risk_assessment_id' => RiskAssessment::factory(),
            'user_id' => $user,
            'context' => 'order',
            'status' => RiskReviewStatus::Pending,
            'score' => 60,
            'band' => 'HIGH',
            'reason_codes' => ['UNVERIFIED_HIGH_VALUE', 'VELOCITY_USER_1H'],
        ];
    }
}
