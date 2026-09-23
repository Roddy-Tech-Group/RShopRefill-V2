<?php

namespace Database\Factories;

use App\Domain\Catalog\Enums\CatalogFeed;
use App\Domain\Catalog\Enums\CatalogSyncRunStatus;
use App\Models\CatalogSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSyncRun>
 */
class CatalogSyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feed' => CatalogFeed::ZenditVouchers,
            'status' => CatalogSyncRunStatus::Running,
            'started_at' => now(),
        ];
    }

    /**
     * A finished run whose seen count becomes the next run's shrink baseline.
     */
    public function completed(int $offersSeen = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CatalogSyncRunStatus::Completed,
            'finished_at' => now(),
            'offers_seen' => $offersSeen,
        ]);
    }
}
