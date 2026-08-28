<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The '*' view composers resolve their data once per request.
 *
 * Both composers run for EVERY view rendered - a listing page renders
 * ~1,800 of them. Unmemoized, the site name was ~1,800 cache-table queries
 * per page on the database cache driver, and the cart was resolved and
 * priced ~1,800 times per page for anyone signed in. Each now memoizes on
 * the request instance, so the first resolution serves every view.
 */
class ViewComposerMemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_site_name_is_read_once_per_request_not_once_per_view(): void
    {
        // The array driver tests default to hides the problem: only the
        // database driver pays a query per cache read, as production does.
        config(['cache.default' => 'database']);

        $lookups = 0;
        DB::listen(function ($query) use (&$lookups) {
            if (str_contains($query->sql, 'from `cache`') && str_contains((string) ($query->bindings[0] ?? ''), 'site.name')) {
                $lookups++;
            }
        });

        $this->get('/')->assertOk();

        // One real read (plus at most the cache-miss write-back path).
        $this->assertLessThanOrEqual(2, $lookups);
    }

    public function test_the_cart_is_resolved_once_per_request_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $cartQueries = 0;
        DB::listen(function ($query) use (&$cartQueries) {
            if (str_contains($query->sql, '`carts`')) {
                $cartQueries++;
            }
        });

        $this->actingAs($user)->get('/')->assertOk();

        // firstOrCreate is a select plus an insert; per-view resolution was
        // one of those for every view rendered.
        $this->assertLessThanOrEqual(3, $cartQueries);
    }
}
