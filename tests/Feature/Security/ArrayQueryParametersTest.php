<?php

namespace Tests\Feature\Security;

use App\Domain\Admin\Enums\AdminRole;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A query parameter sent as an array must not take a page down.
 *
 * `?subcategory[]=x` makes request()->query('subcategory') an array, and the
 * catalogue pages cast it straight to a string: PHP's "Array to string
 * conversion" is fatal, so one crafted or mangled link answered the top-ups
 * page with a 500 in production (Sentry 7770706387, 2026-10-03). Every page
 * that reads a filter from the URL now reads it through queryString(), which
 * answers the default for anything that is not a single value.
 */
class ArrayQueryParametersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_accessor_answers_the_default_for_anything_but_a_single_value(): void
    {
        $request = Request::create('/x', 'GET', [
            'list' => ['a', 'b'],
            'nested' => ['k' => 'v'],
            'word' => 'hello',
            'number' => '42',
            'blank' => '',
        ]);

        $this->assertSame('', $request->queryString('list'));
        $this->assertSame('popular', $request->queryString('nested', 'popular'));
        $this->assertSame('hello', $request->queryString('word'));
        $this->assertSame('42', $request->queryString('number'));
        $this->assertSame('', $request->queryString('blank', 'fallback'), 'present but empty is empty, not the default');
        $this->assertSame('US', $request->queryString('missing', 'US'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function storefrontPages(): array
    {
        $filters = 'q[]=a&currency[]=NGN&subcategory[]=x&sort[]=popular';

        return [
            'top-ups' => ['/topups?'.$filters],
            'bills' => ['/bills?'.$filters],
            'gift cards' => ['/gift-cards?'.$filters],
            'contact' => ['/contact?subject[]=hello'],
            // The region, currency and referral middleware read these on EVERY
            // page, so an array here used to take any URL down, not one page.
            'home with array middleware parameters' => ['/?country[]=NG&display_currency[]=NGN&ref[]=abc'],
            'esims scope' => ['/esims?scope[]=popular'],
            // The cart's JSON endpoint typed its currency parameter as a string;
            // an array was a TypeError on every cart read.
            'cart data currency' => ['/cart/data?currency[]=USD'],
            'storefront catalogue search' => ['/api/storefront/products?search[]=a&country[]=NG&category[]=x'],
        ];
    }

    public function test_the_rewards_page_survives_an_array_tab_parameter(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->withoutVite()->get('/dashboard/rewards?tab[]=refer')->assertOk();
    }

    #[DataProvider('storefrontPages')]
    public function test_a_storefront_page_survives_array_filters(string $url): void
    {
        $this->withoutVite()->get($url)->assertOk();
    }

    public function test_the_wallet_sheet_survives_an_array_fund_parameter(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->withoutVite()->get('/dashboard?fund[]=NGN&amount[]=5')->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adminPages(): array
    {
        // The admin products page is covered by the source check below rather
        // than a request: it queries MySQL-only JSON functions and cannot
        // render on the sqlite test database at all.
        return [
            'transactions' => ['/admin/transactions?status[]=completed&q[]=abc'],
            'search' => ['/admin/search-suggest?q[]=abc'],
            'risk reviews' => ['/admin/risk/reviews?status[]=pending'],
            'dashboard filters' => ['/admin/dashboard?range[]=7d&country_cat[]=all'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_an_admin_page_survives_array_filters(string $url): void
    {
        $this->seed(AdminSeeder::class);
        $admin = Admin::firstOrCreate(
            ['email' => 'array-query-admin@example.test'],
            ['name' => 'Array Query Admin', 'password' => 'password', 'role' => AdminRole::SuperAdmin, 'is_active' => true],
        );

        $response = $this->actingAs($admin, 'admin')->withoutVite()->get($url);

        $this->assertNotSame(500, $response->status(), $url.' answered a server error');
        $this->assertTrue($response->isSuccessful() || $response->isRedirect(), $url.' answered '.$response->status());
    }

    /**
     * No page in the tree casts a query parameter to a string any more, through
     * any of the spellings: `(string) request()->query(..)`, `(string)
     * $request->query(..)`, `->get(..)`, or the `(string) request('key')` helper
     * form the rewards page used. Casts of `->input(..)` are left alone: those
     * sit behind a Form Request whose `string` rule has already refused an
     * array, and the two that did not (checkout currency, contact URL) now read
     * through inputString().
     */
    public function test_no_view_or_controller_casts_a_query_parameter_to_a_string(): void
    {
        $offenders = [];

        foreach ([resource_path('views'), app_path('Http'), app_path('Livewire')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match('/\(string\)\s*(?:(?:request\(\)|\$request)->(?:query|get)\(|request\(\s*[\'"])/', $source) === 1) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'These files cast a query parameter to a string; use queryString() instead.');
    }
}
