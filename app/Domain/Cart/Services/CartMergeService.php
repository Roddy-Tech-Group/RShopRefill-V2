<?php

namespace App\Domain\Cart\Services;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CartMergeService
{
    /**
     * Merges a guest cart into a user's authenticated cart.
     * Deletes the guest cart afterward.
     */
    public function mergeGuestCart(string $guestToken, User $user): ?Cart
    {
        $guestCart = Cart::with('items')->byToken($guestToken)->active()->first();

        if (! $guestCart || $guestCart->items->isEmpty()) {
            // Nothing to merge
            if ($guestCart) {
                $guestCart->delete();
            }

            return Cart::byUser($user->id)->active()->first();
        }

        return DB::transaction(function () use ($guestCart, $user) {
            // 1. Get or create user cart
            $userCart = Cart::firstOrCreate(
                ['user_id' => $user->id, 'status' => 'active'],
                ['last_activity_at' => now()]
            );

            // 2. Iterate through guest items and merge
            foreach ($guestCart->items as $guestItem) {
                // Find existing item in user cart for same variant
                $existingItem = $userCart->items()
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->first();

                if ($existingItem) {
                    // Merge quantities
                    $newQuantity = $existingItem->quantity + $guestItem->quantity;

                    // We assume the user cart's snapshot takes precedence for pricing
                    // If we wanted to be perfectly strict, we'd keep them as separate line items
                    // if their unit_price_snapshots differed. For now, we merge and recalculate
                    // the subtotal based on the existing unit price.

                    $existingItem->update([
                        'quantity' => $newQuantity,
                        'subtotal_snapshot' => $existingItem->unit_price_snapshot * $newQuantity,
                        'display_amount' => ($existingItem->display_amount / $existingItem->quantity) * $newQuantity,
                    ]);
                } else {
                    // Move guest item to user cart
                    $guestItem->update([
                        'cart_id' => $userCart->id,
                    ]);
                }
            }

            // 3. Delete old guest cart
            $guestCart->delete();

            // 4. Touch user cart
            $userCart->touch('last_activity_at');

            return $userCart->load('items');
        });
    }
}
