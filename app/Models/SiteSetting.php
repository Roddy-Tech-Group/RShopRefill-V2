<?php

namespace App\Models;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generic site-wide key/value setting. Use the `get()` / `put()` statics so
 * the cache stays consistent — direct Eloquent writes skip cache invalidation.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property string $group
 * @property string|null $description
 */
class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'description'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * Read a setting with a default fallback. Cached for an hour so hot keys
     * (homepage hero stats, review aggregate) don't hit the DB on every page
     * render. Call `put()` to update — it busts the cache key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $cached = Cache::remember(
                self::cacheKey($key),
                now()->addHour(),
                fn () => self::query()->where('key', $key)->value('value'),
            );
        } catch (\Throwable $e) {
            // A DB or cache blip must never 500 a page: these reads happen ~10x in
            // <head> on every render (SEO directives, brand name, social profiles),
            // so an unguarded failure took down the whole page for crawlers. Fall
            // back to the caller's default and let the page serve. Nothing is
            // cached, so the real value returns as soon as the store recovers.
            return $default;
        }

        return $cached !== null ? $cached : $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general', ?string $description = null): self
    {
        // CREATING a setting is not FLIPPING a setting: side effects below
        // (outage broadcasts, SEV-2 gateway alerts) must only fire on a real
        // state change. Without this, seeding a backup gateway 'off' on a
        // fresh deploy paged the on-call with a false "gateway DISABLED".
        $existedBefore = self::query()->where('key', $key)->exists();
        $oldValue = self::get($key);

        $setting = self::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'description' => $description],
        );

        Cache::forget(self::cacheKey($key));

        if ($existedBefore && $key === 'features.gift_cards_enabled') {
            $isTruthy = fn ($val) => in_array(strtolower((string) $val), ['on', 'true', '1'], true);
            $wasOn = $isTruthy($oldValue ?? 'on');
            $isOn = $isTruthy($value);

            if ($wasOn && ! $isOn) {
                Artisan::call('app:broadcast-gift-card-outage');
            } elseif (! $wasOn && $isOn) {
                Artisan::call('notify:giftcards-restored');
            }
        }

        // A payment gateway kill switch flipping is an operational event that
        // must never be invisible history: whoever is on call sees WHO turned
        // WHAT off, the moment it happens, next to the provider-health alerts
        // it usually accompanies.
        if ($existedBefore && str_starts_with($key, 'features.gateway_') && str_ends_with($key, '_enabled')) {
            $isTruthy = fn ($val) => in_array(strtolower((string) $val), ['on', 'true', '1'], true);
            $wasOn = $isTruthy($oldValue ?? 'on');
            $isOn = $isTruthy($value);

            if ($wasOn !== $isOn) {
                $gateway = str_replace(['features.gateway_', '_enabled'], '', $key);

                try {
                    app(OperationalAlerter::class)->send(
                        AlertSeverity::Sev2,
                        // Distinct per gateway AND direction, so re-enabling is
                        // never throttled away by the disable alert before it.
                        'gateway_flag.'.$gateway.'.'.($isOn ? 'enabled' : 'disabled'),
                        'Payment gateway '.($isOn ? 'RE-ENABLED' : 'DISABLED').': '.$gateway,
                        $isOn
                            ? "The {$gateway} kill switch was turned back on. New payments through it resume immediately."
                            : "New payments through {$gateway} are now refused; its checkout tiles are greyed out. In-flight payments and webhooks still process. Re-enable via admin settings once the provider recovers.",
                        ['flag' => $key, 'actor' => auth()->user()?->email ?? 'system'],
                    );
                } catch (\Throwable $e) {
                    // Alerting must never block the toggle itself: flipping the
                    // switch during an incident is the one moment it has to work.
                    Log::error('Gateway flag alert failed', ['key' => $key, 'error' => $e->getMessage()]);
                }
            }
        }

        // Category-eligibility enforcement changes what EVERY customer can
        // buy; flipping it is never routine, so it gets the same on-call
        // visibility as the gateway kill switches.
        if ($existedBefore && $key === 'features.payment_eligibility_enforce_enabled') {
            $isTruthy = fn ($val) => in_array(strtolower((string) $val), ['on', 'true', '1'], true);
            $wasOn = $isTruthy($oldValue ?? 'off');
            $isOn = $isTruthy($value);

            if ($wasOn !== $isOn) {
                try {
                    app(OperationalAlerter::class)->send(
                        AlertSeverity::Sev2,
                        'eligibility_enforce.'.($isOn ? 'enabled' : 'disabled'),
                        'Payment eligibility enforcement '.($isOn ? 'ENABLED' : 'DISABLED'),
                        $isOn
                            ? 'The category x gateway eligibility matrix is now ENFORCED: carts are refused on any gateway without an approved cell. Verify the approved cells match real provider approvals.'
                            : 'Payment eligibility enforcement is back in shadow mode: denials are logged but nothing is refused.',
                        ['flag' => $key, 'actor' => auth()->user()?->email ?? 'system'],
                    );
                } catch (\Throwable $e) {
                    Log::error('Eligibility enforce alert failed', ['key' => $key, 'error' => $e->getMessage()]);
                }
            }
        }

        return $setting;
    }

    private static function cacheKey(string $key): string
    {
        return 'site_setting:'.$key;
    }
}
