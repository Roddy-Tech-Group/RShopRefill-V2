<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * Old URL shapes Google still has indexed (surfacing as 404s in Search Console)
 * 301 onto the current structure so their crawl equity lands on a live page.
 */
class LegacyRedirectsTest extends TestCase
{
    public function test_legacy_paths_301_to_current_structure(): void
    {
        $this->get('/giftcards')->assertStatus(301)->assertRedirect('/gift-cards');
        $this->get('/top-ups')->assertStatus(301)->assertRedirect('/topups');
        $this->get('/top-ups/metropcs-us')->assertStatus(301)->assertRedirect('/topups/metropcs-us');
        $this->get('/en')->assertStatus(301)->assertRedirect('/');
    }
}
