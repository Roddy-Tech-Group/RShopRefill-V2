<?php

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Guards the trusted-proxy config in bootstrap/app.php. The app runs behind
 * Cloudflare -> the VPS proxy, so without this config Request::ip() (and every
 * audit log) records the local hop (127.0.0.1) instead of the real visitor.
 */
class TrustedProxiesTest extends TestCase
{
    public function test_real_client_ip_is_read_from_forwarded_header_behind_a_trusted_proxy(): void
    {
        Route::get('/__ip_probe', fn () => request()->ip());

        // REMOTE_ADDR is a Cloudflare edge range (trusted); the real visitor
        // rides in X-Forwarded-For and is what we must resolve + log.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->get('/__ip_probe', ['X-Forwarded-For' => '203.0.113.42']);

        $response->assertOk();
        $this->assertSame('203.0.113.42', $response->getContent());
    }

    public function test_a_trusted_local_hop_still_resolves_the_forwarded_client(): void
    {
        Route::get('/__ip_probe_local', fn () => request()->ip());

        // The internal nginx -> PHP hop (127.0.0.1) is trusted, so the forwarded
        // client is still surfaced rather than the loopback address.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/__ip_probe_local', ['X-Forwarded-For' => '203.0.113.42']);

        $response->assertOk();
        $this->assertSame('203.0.113.42', $response->getContent());
    }

    public function test_an_untrusted_source_cannot_spoof_its_ip_via_forwarded_header(): void
    {
        Route::get('/__ip_probe_spoof', fn () => request()->ip());

        // A connection from an address outside the trusted list must NOT be able
        // to override its IP through X-Forwarded-For.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->get('/__ip_probe_spoof', ['X-Forwarded-For' => '203.0.113.42']);

        $response->assertOk();
        $this->assertSame('198.51.100.9', $response->getContent());
    }
}
