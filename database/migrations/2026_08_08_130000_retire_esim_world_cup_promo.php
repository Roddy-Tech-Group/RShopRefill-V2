<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Retires the World Cup eSIM advert now the tournament has passed.
 *
 * The third hero card keeps its slot; only its content changes, to copy that
 * does not expire. The illustration moves from the football stadium scene to
 * a globe (illos.esim-globe) in the view.
 *
 * The first-visit tip modal had its heading and body written directly into the
 * Blade, so "Heading to the World Cup, or travelling soon?" could not be
 * changed without a deploy. Those become settings here, alongside the promo
 * rows they sit next to, so the next seasonal push is an admin edit.
 *
 * Existing values are only rewritten when they still match the World Cup seed.
 * An admin who has already reworded a card keeps their wording.
 */
return new class extends Migration
{
    private const RETIRED = [
        'esim.promo3_heading' => 'Heading to the World Cup? Travel data sorted',
        'esim.promo3_text' => 'Follow every match across the host cities without hunting for WiFi. Activate a travel eSIM before you fly and stay online from kickoff to the final whistle.',
    ];

    private const REPLACEMENT = [
        'esim.promo3_heading' => [
            'value' => 'Set up in minutes, before you even pack',
            'description' => 'eSIM hero advert 3 (globe scene) - heading.',
        ],
        'esim.promo3_text' => [
            'value' => 'Buy your data plan, scan the QR code and you are ready to go. No shop queues, no plastic SIM to lose, and your usual number keeps working for calls and messages.',
            'description' => 'eSIM hero advert 3 - supporting text.',
        ],
    ];

    private const TIP_SETTINGS = [
        'esim.tip_heading' => [
            'value' => 'Where are you going?',
            'description' => 'First-visit eSIM popup - heading.',
        ],
        'esim.tip_text' => [
            'value' => 'Travelling soon? Get online the moment you land.',
            'description' => 'First-visit eSIM popup - short line under the heading.',
        ],
        'esim.tip_body' => [
            'value' => 'Get an eSIM and stay connected with your family and loved ones, anywhere you go - instant data in 190+ countries, no SIM swap.',
            'description' => 'First-visit eSIM popup - body paragraph.',
        ],
    ];

    public function up(): void
    {
        foreach (self::REPLACEMENT as $key => $new) {
            $current = trim((string) SiteSetting::get($key, ''));

            // Only replace the retired copy. A reworded card is left alone.
            if ($current === '' || $current === self::RETIRED[$key]) {
                SiteSetting::put($key, $new['value'], 'esim', $new['description']);
            }

            Cache::forget('site_setting:'.$key);
        }

        foreach (self::TIP_SETTINGS as $key => $setting) {
            if (! SiteSetting::query()->where('key', $key)->exists()) {
                SiteSetting::put($key, $setting['value'], 'esim', $setting['description']);
            }

            Cache::forget('site_setting:'.$key);
        }
    }

    public function down(): void
    {
        foreach (self::RETIRED as $key => $value) {
            SiteSetting::put($key, $value, 'esim', null);
            Cache::forget('site_setting:'.$key);
        }

        foreach (array_keys(self::TIP_SETTINGS) as $key) {
            SiteSetting::query()->where('key', $key)->delete();
            Cache::forget('site_setting:'.$key);
        }
    }
};
