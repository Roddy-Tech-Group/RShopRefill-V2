<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * RedirectAlternateHosts folds the www/non-www sibling and any explicit
 * secondary domains onto the single canonical host, and stays off for
 * non-domain (local dev / IP) hosts.
 */
class RedirectAlternateHostsTest extends TestCase
{
    public function test_non_www_folds_to_www_from_canonical_host_alone(): void
    {
        // No explicit alternate_hosts needed - the sibling is derived from the
        // canonical host (APP_URL), so www-enforcement works out of the box.
        config(['app.canonical_host' => 'www.rshoprefill.com', 'app.alternate_hosts' => []]);

        $this->get('https://rshoprefill.com/robots.txt?ref=abc')
            ->assertStatus(301)
            ->assertRedirect('https://www.rshoprefill.com/robots.txt?ref=abc');
    }

    public function test_canonical_www_host_passes_through(): void
    {
        config(['app.canonical_host' => 'www.rshoprefill.com', 'app.alternate_hosts' => []]);

        $this->get('https://www.rshoprefill.com/robots.txt')->assertOk();
    }

    public function test_explicit_alternate_domain_also_folds_to_canonical(): void
    {
        config(['app.canonical_host' => 'www.rshoprefill.com', 'app.alternate_hosts' => ['rshoprefills.com']]);

        $this->get('https://rshoprefills.com/robots.txt')
            ->assertStatus(301)
            ->assertRedirect('https://www.rshoprefill.com/robots.txt');
    }

    public function test_non_domain_canonical_never_redirects(): void
    {
        // localhost / IP origins (local dev, health checks) must never trigger the
        // www/non-www auto-fold.
        config(['app.canonical_host' => 'localhost', 'app.alternate_hosts' => []]);

        $this->get('https://rshoprefill.com/robots.txt')->assertOk();
    }
}
