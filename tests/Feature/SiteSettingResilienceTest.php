<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SiteSetting::get() must degrade to the caller's default when the cache/DB is
 * unavailable, never bubble an exception. These reads run ~10x in <head> on
 * every page, so an unguarded failure was 500ing whole pages for crawlers
 * (Search Console "Server error (5xx)").
 */
class SiteSettingResilienceTest extends TestCase
{
    public function test_get_returns_default_when_the_store_throws(): void
    {
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('store down'));

        $this->assertSame('RshopRefills', SiteSetting::get('site.name', 'RshopRefills'));
        $this->assertNull(SiteSetting::get('missing.key'));
    }
}
