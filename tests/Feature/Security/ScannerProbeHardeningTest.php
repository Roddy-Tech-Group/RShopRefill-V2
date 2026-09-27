<?php

namespace Tests\Feature\Security;

use App\Models\NotificationInteraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Replays the payloads an external scanner sent at the storefront on
 * 2026-09-12 from 2.28.51.145.
 *
 * None of them achieved anything: the injection probe was bound as a
 * parameter and never parsed as SQL. What they did achieve was three 500s,
 * because the endpoints accepted free text and only failed later - at the
 * column width, or inside json_encode while the response was being built.
 * Each case below asserts the request is now refused, or absorbed, at the
 * edge instead.
 */
class ScannerProbeHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cart:r:127.0.0.1');
        RateLimiter::clear('cart:w:127.0.0.1');
    }

    /** The exact time-based injection probe that hit /api/push/track. */
    public function test_a_sql_injection_probe_in_channel_is_refused_without_touching_the_database(): void
    {
        $response = $this->postJson('/api/push/track', [
            'type' => 'clicked',
            'channel' => 'if(now()=sysdate(),sleep(15),0)',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, NotificationInteraction::count());
    }

    /** The second probe, which is what actually reached the INSERT. */
    public function test_a_subquery_probe_in_channel_is_refused(): void
    {
        $response = $this->postJson('/api/push/track', [
            'type' => 'clicked',
            'channel' => '(select 198766*667891)',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, NotificationInteraction::count());
    }

    /** Any channel outside the enum, however harmless-looking. */
    public function test_an_unknown_channel_is_refused(): void
    {
        $this->postJson('/api/push/track', [
            'type' => 'clicked',
            'channel' => 'web',
        ])->assertStatus(422);
    }

    /** Regression guard: the service worker only ever sends this. */
    public function test_a_real_push_click_still_records(): void
    {
        $response = $this->postJson('/api/push/track', [
            'type' => 'clicked',
            'channel' => 'push',
            'metadata' => ['url' => '/orders'],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, NotificationInteraction::count());
        $this->assertSame('push', NotificationInteraction::first()->channel);
    }

    /** Metadata is written verbatim into a JSON column, so it must be a structure. */
    public function test_metadata_must_be_a_structure_not_free_text(): void
    {
        $this->postJson('/api/push/track', [
            'type' => 'clicked',
            'channel' => 'push',
            'metadata' => 'not-an-array',
        ])->assertStatus(422);
    }

    /**
     * The /cart/data and /cart/items 500s. Malformed UTF-8 reached
     * json_encode through the echoed `currency` field and threw there.
     */
    public function test_malformed_utf8_in_a_query_parameter_is_a_422_not_a_500(): void
    {
        $response = $this->get('/cart/data?currency='.urlencode("\xB1\x31\xC0"));

        $response->assertStatus(422);
    }

    public function test_malformed_utf8_is_refused_on_the_cart_write_route_too(): void
    {
        $response = $this->postJson('/cart/items?currency='.urlencode("\xFE\xFF\x80"), []);

        $response->assertStatus(422);
    }

    public function test_an_oversized_query_value_is_refused(): void
    {
        $response = $this->get('/cart/data?currency='.str_repeat('A', 5000));

        $response->assertStatus(422);
    }

    /**
     * Well-formed but not a currency we offer. This is the allowlist's job
     * rather than the middleware's, so it degrades to USD instead of 422 -
     * a stale bookmark with a dead currency should still show a cart.
     */
    public function test_an_unsupported_currency_falls_back_to_usd(): void
    {
        $this->get('/cart/data?currency=ZZZ')
            ->assertOk()
            ->assertJson(['currency' => 'USD']);
    }

    public function test_a_non_iso_shaped_currency_falls_back_to_usd(): void
    {
        $this->get('/cart/data?currency='.urlencode('<script>'))
            ->assertOk()
            ->assertJson(['currency' => 'USD']);
    }

    /** Regression guard: a currency the locale modal can actually produce. */
    public function test_a_supported_currency_is_still_honoured(): void
    {
        $this->get('/cart/data?currency=NGN')
            ->assertOk()
            ->assertJson(['currency' => 'NGN']);
    }

    /** A scanner hammering the cart is cut off rather than served forever. */
    public function test_the_cart_read_route_is_rate_limited(): void
    {
        for ($i = 0; $i < 241; $i++) {
            $response = $this->get('/cart/data');

            if ($response->getStatusCode() === 429) {
                $this->assertGreaterThan(200, $i, 'Cut off far too early for normal browsing.');

                return;
            }
        }

        $this->fail('/cart/data served 241 requests in a minute without throttling.');
    }
}
