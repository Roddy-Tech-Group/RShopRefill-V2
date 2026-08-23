<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds the two feature flags governing the risk engine, both OFF.
 *
 *  - features.risk_engine_enabled: master switch for scoring at all. Off means
 *    the engine does not even run, so this ships completely inert.
 *  - features.risk_engine_enforce_enabled: shadow vs enforce. With scoring on
 *    but enforce off (shadow), assess() scores, persists an immutable
 *    risk_assessments row, and logs the would-be outcome, but returns allow and
 *    turns no customer away.
 *
 * Same dual-flag shadow -> enforce discipline as PaymentBackoffManager and the
 * KYC tier engine. Both seeded off so deploying Stage 1 changes nothing a
 * customer experiences; scoring is switched on to begin collecting the decision
 * history, and enforcement is flipped only after that shadow data validates the
 * thresholds.
 */
return new class extends Migration
{
    // Must match the keys FeatureFlag::on() resolves to (prefix 'features.',
    // suffix '_enabled').
    private const FLAGS = [
        'features.risk_engine_enabled' => 'Master switch for the fraud/risk engine. On runs scoring; off leaves it completely inert. Seeded off.',
        'features.risk_engine_enforce_enabled' => 'Risk engine enforcement. On acts on the decision (step-up/review/deny); off is shadow mode: decisions are scored, persisted and logged, no customer is turned away. Seeded off.',
    ];

    public function up(): void
    {
        foreach (self::FLAGS as $key => $description) {
            if (! SiteSetting::query()->where('key', $key)->exists()) {
                SiteSetting::put($key, 'off', 'features', $description);
            }

            Cache::forget('site_setting:'.$key);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FLAGS) as $key) {
            SiteSetting::query()->where('key', $key)->delete();
            Cache::forget('site_setting:'.$key);
        }
    }
};
