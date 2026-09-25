<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SiteSetting::get() must degrade to the caller's default when the cache/DB is
 * unavailable, never bubble an exception. These reads run ~10x in <head> on
 * every page, so an unguarded failure was 500ing whole pages for crawlers
 * (Search Console "Server error (5xx)").
 *
 * It also remembers a key for the rest of the request it was read on, and only
 * for that request. Both halves of that are load-bearing and both are pinned
 * below.
 */
class SiteSettingResilienceTest extends TestCase
{
    /** Binds a request that has been through the router, as a page render has. */
    private function bindRoutedRequest(): void
    {
        $request = Request::create('/');
        $request->setRouteResolver(fn () => Route::newRoute('GET', '/', []));

        $this->app->instance('request', $request);
    }

    public function test_repeated_reads_only_touch_the_shared_cache_once_per_request(): void
    {
        $this->bindRoutedRequest();

        Cache::shouldReceive('remember')->once()->andReturn('on');

        $this->assertSame('on', SiteSetting::get('performance.memoization.test'));
        $this->assertSame('on', SiteSetting::get('performance.memoization.test'));
        $this->assertSame('on', SiteSetting::get('performance.memoization.test'));
    }

    /**
     * Outside a request there is no memo, and that is the point.
     *
     * A console process - artisan, the scheduler, a queue worker - is handed
     * ONE synthetic request at boot and never another, so a memo there lasts as
     * long as the process. A Horizon worker lives up to an hour, which is how
     * long it would go on answering with a flag's old value after an admin
     * turned it off. Payment insights, device management and the queued mail's
     * brand settings are all read in that context.
     */
    public function test_a_console_process_never_remembers_a_setting(): void
    {
        Cache::shouldReceive('remember')->times(3)->andReturn('on');

        $this->assertSame('on', SiteSetting::get('performance.memoization.console'));
        $this->assertSame('on', SiteSetting::get('performance.memoization.console'));
        $this->assertSame('on', SiteSetting::get('performance.memoization.console'));
    }

    /**
     * Writing forgets the memo instead of overwriting it, so a read after a
     * rolled-back write goes back to the row rather than serving a value the
     * database never kept.
     */
    public function test_forgetting_a_key_clears_the_memo_as_well_as_the_cache(): void
    {
        $this->bindRoutedRequest();

        Cache::shouldReceive('remember')->twice()->andReturn('on');
        Cache::shouldReceive('forget')->once();

        $this->assertSame('on', SiteSetting::get('performance.memoization.forget'));
        SiteSetting::forget('performance.memoization.forget');
        $this->assertSame('on', SiteSetting::get('performance.memoization.forget'));
    }

    public function test_get_returns_default_when_the_store_throws(): void
    {
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('store down'));

        $this->assertSame('RshopRefills', SiteSetting::get('site.name', 'RshopRefills'));
        $this->assertNull(SiteSetting::get('missing.key'));
    }
}
