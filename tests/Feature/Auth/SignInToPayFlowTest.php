<?php

namespace Tests\Feature\Auth;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Stevebauman\Location\Facades\Location;
use Tests\TestCase;

/**
 * The "Sign in to pay" journey: a guest who filled a cart and hit the sign-in
 * wall on checkout must (1) land back on the checkout page after logging in,
 * never the dashboard, and (2) keep the cart - the guest cookie cart merges
 * into the account at sign-in. The return path is client-supplied, so it is
 * honoured only when it is a safe same-site path.
 */
class SignInToPayFlowTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'buyer@example.test',
            'password' => Hash::make('Secret123!'),
        ]);
    }

    /** A guest cart holding one item, keyed by the given cookie token. */
    private function seedGuestCart(string $token): Cart
    {
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $cart = Cart::create([
            'guest_token' => $token,
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
            'display_currency' => 'USD',
            'display_amount' => 20.00,
            'provider_cost_usd' => 8.00,
            'markup_amount' => 2.00,
            'unit_price_snapshot' => 10.00,
            'subtotal_snapshot' => 20.00,
        ]);

        return $cart;
    }

    public function test_login_returns_the_customer_to_the_page_that_asked(): void
    {
        $this->customer();

        Volt::test('auth.auth-modal')
            ->set('returnTo', '/checkout?currency=NGN')
            ->set('loginEmail', 'buyer@example.test')
            ->set('loginPassword', 'Secret123!')
            ->call('login')
            ->assertRedirect('/checkout?currency=NGN');
    }

    public function test_an_unsafe_return_path_falls_back_to_the_dashboard(): void
    {
        $this->customer();

        // Absolute and protocol-relative URLs must never be honoured - the
        // property is client-mutable and would otherwise be an open redirect.
        foreach (['https://evil.example/phish', '//evil.example/phish', 'javascript:alert(1)'] as $evil) {
            Volt::test('auth.auth-modal')
                ->set('returnTo', $evil)
                ->set('loginEmail', 'buyer@example.test')
                ->set('loginPassword', 'Secret123!')
                ->call('login')
                ->assertRedirect(route('dashboard', absolute: false));

            auth()->logout();
        }
    }

    public function test_signing_in_merges_the_guest_cookie_cart_into_the_account(): void
    {
        Location::shouldReceive('get')->andReturn(false);

        $user = $this->customer();
        $token = Str::uuid()->toString();
        $this->seedGuestCart($token);

        // MergeGuestCartOnLogin reads the guest cookie off the current
        // request when the Login event fires - the same event Auth::attempt
        // and Auth::login raise on every sign-in surface.
        request()->cookies->set('guest_token', $token);

        event(new Login('web', $user, false));

        // The guest cart is gone; its item now lives on the account's cart.
        $this->assertNull(Cart::byToken($token)->active()->first());

        $userCart = Cart::byUser($user->id)->active()->first();
        $this->assertNotNull($userCart, 'the guest cart must merge into the account at sign-in');
        $this->assertSame(2, (int) $userCart->items()->sum('quantity'));
    }

    public function test_auth_login_itself_triggers_the_merge_covering_registration_and_google(): void
    {
        Location::shouldReceive('get')->andReturn(false);

        $user = $this->customer();
        $token = Str::uuid()->toString();
        $this->seedGuestCart($token);

        request()->cookies->set('guest_token', $token);

        // Registration and the Google callback both call Auth::login, which
        // fires the Login event - proving the one listener covers them all.
        Auth::login($user);

        $this->assertNull(Cart::byToken($token)->active()->first());
        $this->assertSame(
            2,
            (int) Cart::byUser($user->id)->active()->first()?->items()->sum('quantity'),
        );
    }
}
