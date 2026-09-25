<?php

namespace App\Models;

use App\Domain\Shared\Services\OperationalAlerter;
use App\Enums\AlertSeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        // A single page can ask for the same feature flag dozens of times from
        // the layout, nav, footer and checkout method guards. The shared cache
        // is database-backed in production, so Cache::remember() is still a
        // query even on a hit. Keep the raw result on the current request after
        // the first lookup.
        $request = self::memoizableRequest();
        $memoKey = self::requestMemoKey($key);

        if ($request && $request->attributes->has($memoKey)) {
            $memoized = $request->attributes->get($memoKey);

            return $memoized !== null ? $memoized : $default;
        }

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

        $request?->attributes->set($memoKey, $cached);

        return $cached !== null ? $cached : $default;
    }

    /**
     * The request a lookup may be remembered on, or null when there is none.
     *
     * Only a ROUTED request qualifies, and that is the whole guard. A console
     * process - phpunit, artisan, a queue worker, Horizon - is given one
     * synthetic Request at boot by SetRequestForConsole and never given
     * another, so memoizing against it is memoizing for the life of the
     * process: a Horizon worker runs for up to an hour (config/horizon.php),
     * and it would answer every read of a flag with whatever that flag said
     * when the worker first asked. An admin turning payment insights off would
     * not reach the workers until they recycled. A real HTTP request has a
     * route by the time anything reads a setting, and dies at the end of it,
     * which is the lifetime this memo was meant to have.
     */
    private static function memoizableRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }

    /**
     * Drop a setting from every layer that holds it.
     *
     * Anything that changes a row behind the model's back - a query-builder
     * write, a rolled-back transaction, a test tearing its fixture down - must
     * come through here, because forgetting the shared cache alone leaves the
     * current request answering from its memo.
     */
    public static function forget(string $key): void
    {
        Cache::forget(self::cacheKey($key));

        self::memoizableRequest()?->attributes->remove(self::requestMemoKey($key));
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

        // Drops the memo rather than writing the new value into it, so a read
        // later in this request goes back to the row. That matters when the
        // write is inside a transaction that then rolls back: the memo would
        // otherwise keep serving a value the database never kept.
        self::forget($key);

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

    private static function requestMemoKey(string $key): string
    {
        return 'memo:site_setting:'.sha1($key);
    }
}
