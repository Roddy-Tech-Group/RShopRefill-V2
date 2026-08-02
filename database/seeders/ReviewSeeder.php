<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

/**
 * Public review settings.
 *
 * This seeder used to manufacture 20 reviews attributed to Trustpilot and
 * Google, each written with a name, a date and a body to read like a real
 * customer. They were removed for good on 2026-08-02, for two reasons:
 *
 *   - The storefront renders only reviews our own form collected
 *     (Review::scopeOwnCollected), so they were invisible anyway.
 *   - It used updateOrCreate keyed on author_name + reviewed_at, so every
 *     db:seed resurrected all 20 rows after they were deleted.
 *
 * Do NOT add review rows here. Reviews arrive one of two ways: a customer leaves
 * one through the form on /reviews or an order page (ReviewController, saved
 * unpublished for admin approval), or an admin records one in
 * Admin > Content > Reviews.
 */
class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        // Starting values for the aggregate shown on the nav badge and the
        // /reviews score card. Both are admin-editable without a deploy, so
        // these are a first-run default rather than the live figure.
        SiteSetting::put('reviews.aggregate.rating', 4.5, 'reviews', 'Public aggregate review score');
        SiteSetting::put('reviews.aggregate.count', 24, 'reviews', 'Aggregate review count shown on the score card');
        SiteSetting::put('reviews.aggregate.since_year', 2024, 'reviews', 'Founded year shown on score card');
        SiteSetting::put('reviews.aggregate.source', 'Trustpilot', 'reviews', 'Headline aggregate source label');
    }
}
