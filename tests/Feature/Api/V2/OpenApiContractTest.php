<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Security\Services\ApiTokenService;
use App\Domain\Shared\Enums\Currency;
use App\Models\Category;
use App\Models\CurrencyRate;
use App\Models\Notification;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subcategory;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApiSchemaChecker;
use Tests\TestCase;

/**
 * The contract in docs/api/v2/openapi.yaml describes the code that is actually
 * running. Three things are held to that.
 *
 * Every route is documented and every documented route exists. A door added without
 * a line in the contract, or a line left behind after a door is removed, fails here.
 *
 * Every error type the code can answer with is in the contract's list. The app
 * branches on these; one that is not written down is one the app cannot handle.
 *
 * Real responses match their documented schema. A field renamed, dropped, or typed
 * differently fails here before it fails on somebody's phone.
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-Installation-ID' => 'contract-test-phone-1'];

    /** @var array<string, mixed> */
    private array $spec;

    private OpenApiSchemaChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zendit.api_key' => 'ZENDIT_API_KEY_MOCK',
            'services.flutterwave.secret_key' => 'FLW_SECRET_KEY_MOCK',
            'pricing.safety_markup_percent' => 10.0,
            'pricing.min_margin_percent' => 1.0,
            'pricing.floor_mode' => 'legacy',
        ]);

        Cache::flush();

        $this->spec = Yaml::parseFile(base_path('docs/api/v2/openapi.yaml'));
        $this->checker = new OpenApiSchemaChecker($this->spec);
    }

    // ---------------------------------------------------------------- routes

    public function test_every_v2_route_is_in_the_contract_and_every_contract_path_is_a_route(): void
    {
        $live = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'v2/') && $route->uri() !== 'v2') {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $live[] = strtolower($method).' /'.preg_replace('#^v2/?#', '', $route->uri());
            }
        }

        $documented = [];

        foreach ($this->spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['parameters', 'summary', 'description'], true)) {
                    continue;
                }

                $documented[] = $method.' '.$path;
            }
        }

        sort($live);
        sort($documented);

        $this->assertSame(
            [],
            array_values(array_diff($live, $documented)),
            'These routes exist but are not in the contract.',
        );

        $this->assertSame(
            [],
            array_values(array_diff($documented, $live)),
            'These contract entries have no route behind them.',
        );
    }

    // ----------------------------------------------------------- error types

    public function test_every_error_type_the_code_can_answer_with_is_in_the_contract(): void
    {
        $documented = $this->spec['components']['schemas']['ErrorType']['enum'];

        $inCode = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            // ApiV2Error::make('type', ...) everywhere; the refresh refusals build
            // themselves with new self('type', ...); and two files keep a table of
            // [type, message] pairs.
            preg_match_all("/ApiV2Error::make\(\s*'([a-z_]+)'/", $source, $direct);
            $types = $direct[1];

            if (str_ends_with($file, 'RefreshTokenRejected.php')) {
                preg_match_all("/new self\('([a-z_]+)', '/", $source, $refresh);
                $types = array_merge($types, $refresh[1]);
            }

            if (str_ends_with($file, 'ApiV2Error.php') || str_ends_with($file, 'EnsureApiAccountActive.php')) {
                preg_match_all("/=> \['([a-z_]+)', '/", $source, $tabled);
                $types = array_merge($types, $tabled[1]);
            }

            foreach ($types as $type) {
                $inCode[$type] = true;
            }
        }

        // The envelope's flag translations and status fallbacks.
        foreach (['device_restricted', 'gateway_disabled', 'category_ineligible', 'email_verification_required',
            'account_protected', 'maintenance', 'account_suspended', 'validation_failed', 'unauthenticated',
            'forbidden', 'not_found', 'method_not_allowed', 'conflict', 'payload_too_large', 'upgrade_required',
            'too_many_requests', 'service_unavailable', 'server_error', 'request_failed'] as $type) {
            $inCode[$type] = true;
        }

        $missing = array_values(array_diff(array_keys($inCode), $documented));
        sort($missing);

        $this->assertSame([], $missing, 'The code can answer with error types the contract does not list.');
    }

    // -------------------------------------------------------- live responses

    public function test_public_responses_match_their_schemas(): void
    {
        $this->seedCatalogue();

        $this->assertMatches($this->getJson('/v2/meta'), '/meta', 'get', 200);
        $this->assertMatches($this->getJson('/v2/catalog/config'), '/catalog/config', 'get', 200);
        $this->assertMatches($this->getJson('/v2/catalog/categories'), '/catalog/categories', 'get', 200);
        $this->assertMatches($this->getJson('/v2/catalog/subcategories'), '/catalog/subcategories', 'get', 200);
        $this->assertMatches(
            $this->getJson('/v2/catalog/products', ['X-Display-Currency' => 'NGN']),
            '/catalog/products', 'get', 200,
        );
        $this->assertMatches($this->getJson('/v2/catalog/products/steam-gift-card'), '/catalog/products/{slug}', 'get', 200);
        $this->assertMatches($this->getJson('/v2/catalog/products/nope'), '/catalog/products/{slug}', 'get', 404);
    }

    public function test_error_envelopes_match_their_schemas(): void
    {
        $this->assertMatches($this->getJson('/v2/cart'), '/cart', 'get', 401);
        $this->assertMatches($this->postJson('/v2/auth/login', [], self::HEADERS), '/auth/login', 'post', 422);
        $this->assertMatches($this->postJson('/v2/auth/login', ['email' => 'a@b.c', 'password' => 'x']), '/auth/login', 'post', 400);
        $this->assertMatches(
            $this->postJson('/v2/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'], self::HEADERS),
            '/auth/login', 'post', 401,
        );
        $this->assertMatches(
            $this->postJson('/v2/auth/refresh', ['refresh_token' => str_repeat('x', 64)], self::HEADERS),
            '/auth/refresh', 'post', 401,
        );
    }

    public function test_sign_in_and_account_responses_match_their_schemas(): void
    {
        $this->seedCatalogue();
        $user = $this->customer();

        $login = $this->postJson('/v2/auth/login', ['email' => $user->email, 'password' => 'password'], self::HEADERS);
        $this->assertMatches($login, '/auth/login', 'post', 200);

        $token = $this->tokenFor($user);

        $this->assertMatches($this->getJson('/v2/auth/me', $token), '/auth/me', 'get', 200);
        $this->assertMatches($this->getJson('/v2/account/devices', $token + self::HEADERS), '/account/devices', 'get', 200);
        $this->assertMatches($this->getJson('/v2/account/kyc', $token), '/account/kyc', 'get', 200);
        $this->assertMatches($this->getJson('/v2/transaction-pin', $token), '/transaction-pin', 'get', 200);
        $this->assertMatches($this->getJson('/v2/wallets', $token), '/wallets', 'get', 200);
        $this->assertMatches($this->getJson('/v2/wallets/NGN', $token), '/wallets/{currency}', 'get', 200);
        $this->assertMatches($this->getJson('/v2/wallets/transactions', $token), '/wallets/transactions', 'get', 200);
        $this->assertMatches($this->getJson('/v2/notifications/preferences', $token), '/notifications/preferences', 'get', 200);
        $this->assertMatches($this->getJson('/v2/notifications/unread-count', $token), '/notifications/unread-count', 'get', 200);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'order_update',
            'category' => 'order',
            'title' => 'Order delivered',
            'message' => 'Your Steam card is ready.',
            'channel' => 'in_app',
            'status' => 'sent',
            'priority' => 'normal',
            'sent_at' => now(),
            'metadata' => ['order_number' => 'RSR-20261001-ABCDEF'],
        ]);

        $this->assertMatches($this->getJson('/v2/notifications', $token), '/notifications', 'get', 200);
        $this->assertMatches($this->getJson('/v2/orders', $token), '/orders', 'get', 200);
        $this->assertMatches($this->postJson('/v2/auth/logout', [], $token + self::HEADERS), '/auth/logout', 'post', 200);
    }

    public function test_cart_checkout_and_payment_responses_match_their_schemas(): void
    {
        $this->seedCatalogue();
        $user = $this->customer();
        $token = $this->tokenFor($user);
        $variant = ProductVariant::sole();

        $added = $this->postJson('/v2/cart/items', ['variant_id' => $variant->id, 'quantity' => 1], $token + [
            'Idempotency-Key' => 'contract-add-1',
            'X-Display-Currency' => 'NGN',
        ]);
        $this->assertMatches($added, '/cart/items', 'post', 201);

        $cart = $this->getJson('/v2/cart', $token + ['X-Display-Currency' => 'NGN']);
        $this->assertMatches($cart, '/cart', 'get', 200);

        $placed = $this->postJson('/v2/checkout/orders', [
            'cart_id' => $cart->json('data.id'),
            'payment_method' => 'flutterwave',
            'currency' => 'NGN',
        ], $token + ['Idempotency-Key' => 'contract-order-1']);
        $this->assertMatches($placed, '/checkout/orders', 'post', 201);

        $session = $placed->json('data.payment.id');
        $orderNumber = $placed->json('data.order.order_number');

        $this->assertMatches($this->getJson("/v2/payments/{$session}", $token), '/payments/{session}', 'get', 200);
        $this->assertMatches($this->getJson("/v2/orders/{$orderNumber}", $token), '/orders/{orderNumber}', 'get', 200);
        $this->assertMatches($this->getJson('/v2/orders', $token), '/orders', 'get', 200);
        $this->assertMatches($this->postJson("/v2/payments/{$session}/cancel", [], $token), '/payments/{session}/cancel', 'post', 200);
        $this->assertMatches($this->getJson('/v2/payments/00000000-0000-0000-0000-000000000000', $token), '/payments/{session}', 'get', 404);
    }

    // ---------------------------------------------------------------- helpers

    private function assertMatches(TestResponse $response, string $path, string $method, int $status): void
    {
        $response->assertStatus($status);

        $schema = $this->checker->responseSchema($path, $method, $status);
        $problems = $this->checker->check($response->json(), $schema);

        $this->assertSame(
            [],
            $problems,
            strtoupper($method)." {$path} ({$status}) does not match the contract:\n - ".implode("\n - ", $problems)."\n\nBody: ".$response->getContent(),
        );
    }

    private function customer(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'display_currency' => 'NGN']);

        Wallet::create(['user_id' => $user->id, 'currency' => Currency::USD, 'balance' => 50.0, 'is_active' => true]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function tokenFor(User $user): array
    {
        $pair = app(ApiTokenService::class)->issuePair($user, self::HEADERS['X-Installation-ID']);

        return ['Authorization' => 'Bearer '.$pair->toArray()['access_token']];
    }

    private function seedCatalogue(): void
    {
        CurrencyRate::create(['code' => 'USD', 'name' => 'United States Dollar', 'type' => 'fiat', 'rate_per_usd' => 1.0, 'is_active' => true]);
        CurrencyRate::create(['code' => 'NGN', 'name' => 'Nigerian Naira', 'type' => 'fiat', 'rate_per_usd' => 1600.0, 'is_active' => true]);

        $category = Category::create(['name' => 'Gift Cards', 'slug' => 'gift-cards', 'type' => 'digital']);
        $subcategory = Subcategory::create(['category_id' => $category->id, 'name' => 'Gaming', 'slug' => 'gaming']);
        $product = Product::create([
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'provider_name' => 'zendit',
            'country_code' => 'US',
            'currency_code' => 'USD',
            'name' => 'Steam Gift Card',
            'slug' => 'steam-gift-card',
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'provider_offer_id' => 'steam-offer-10',
            'sku' => 'STEAM10',
            'currency' => 'USD',
            'face_value' => 10.00,
            'cost_price' => 9.50,
            'retail_price' => 10.50,
            'is_available' => true,
        ]);
        PricingRule::create(['markup_type' => 'fixed', 'markup_value' => 1.00, 'is_active' => true]);
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
