<?php

namespace App\Listeners;

use App\Domain\Cart\Services\CartMergeService;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Folds a guest's cookie cart into the account the moment they sign in, so
 * "add to cart -> sign in to pay" keeps the cart. Listening on Login covers
 * every entry point with one seam: the auth modal's login, registration
 * (Auth::login fires Login too), and the Google OAuth callback.
 *
 * A merge fault must never block a sign-in, so failures only log.
 */
class MergeGuestCartOnLogin
{
    public function __construct(private readonly CartMergeService $carts) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return; // admin guard logins carry no storefront cart
        }

        try {
            $request = request();
            $token = (string) ($request->cookie('guest_token') ?? $request->header('X-Guest-Token'));

            if ($token !== '') {
                $this->carts->mergeGuestCart($token, $user);
            }
        } catch (\Throwable $e) {
            Log::warning('auth.guest_cart_merge_failed', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
