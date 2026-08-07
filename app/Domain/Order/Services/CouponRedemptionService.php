<?php

namespace App\Domain\Order\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns the coupon redemption lifecycle: reserve at checkout, redeem on payment
 * confirmation, release on abandonment.
 *
 * The old model incremented `coupons.used_count` when a checkout was created
 * and never decremented it, so abandoned checkouts permanently consumed
 * redemptions and a capped promotion could be exhausted without a single
 * payment. Reservations fix that without opening the opposite hole: a live
 * reservation still counts against max_uses, so a one-use coupon cannot be
 * honoured twice by two customers checking out at the same moment.
 */
class CouponRedemptionService
{
    /**
     * How long a reservation holds its slot.
     *
     * Matches ExpireStalePaymentSessionsJob::MINIMUM_GATEWAY_LIFETIME_MINUTES,
     * the longest window any checkout screen promises the customer (bank
     * transfer). Releasing sooner could free a slot while the customer's money
     * is still in flight, and then refuse their coupon after they have paid.
     */
    public const RESERVATION_MINUTES = 30;

    /**
     * Redemptions currently consuming this coupon's allowance: everything
     * already paid for, plus reservations still inside their window.
     */
    public function consumedCount(Coupon $coupon): int
    {
        return $coupon->redemptions()->consuming()->count();
    }

    /**
     * Reserve a slot for an order that is about to be created.
     *
     * The caller must already hold the coupon row lock (CheckoutService takes
     * it with lockForUpdate before validating), which is what serialises two
     * customers racing for the last use of a coupon.
     */
    public function reserve(Coupon $coupon, Order $order, ?User $user, float $discountUsd): CouponRedemption
    {
        return CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'status' => CouponRedemption::STATUS_RESERVED,
            'discount_usd' => $discountUsd,
            'expires_at' => now()->addMinutes(self::RESERVATION_MINUTES),
        ]);
    }

    /**
     * Confirm the reservation behind a paid order.
     *
     * Deliberately tolerant of a lapsed reservation: if the customer paid after
     * their 30 minutes ran out, the money has arrived and we honour it rather
     * than voiding a discount they were already charged against.
     *
     * Released rows are picked back up for the same reason. A session can be
     * marked failed and then rescued by a late webhook, and that customer has
     * genuinely paid, so their redemption must be recorded even though it was
     * handed back in between. Already-redeemed rows are skipped, which is what
     * makes a replayed webhook safe.
     */
    public function redeemForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $redemptions = CouponRedemption::where('order_id', $order->id)
                ->whereIn('status', [CouponRedemption::STATUS_RESERVED, CouponRedemption::STATUS_RELEASED])
                ->lockForUpdate()
                ->get();

            foreach ($redemptions as $redemption) {
                $redemption->update([
                    'status' => CouponRedemption::STATUS_REDEEMED,
                    'redeemed_at' => now(),
                    'expires_at' => null,
                    'released_at' => null,
                    'release_reason' => null,
                ]);

                $this->syncUsedCount($redemption->coupon);
            }
        });
    }

    /**
     * Give the slot back when a checkout is abandoned, cancelled or fails.
     *
     * Never touches an already-redeemed row: a late confirm can arrive after
     * the session was marked failed, and that customer has paid.
     */
    public function releaseForOrder(Order $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason) {
            $redemptions = CouponRedemption::where('order_id', $order->id)
                ->where('status', CouponRedemption::STATUS_RESERVED)
                ->lockForUpdate()
                ->get();

            foreach ($redemptions as $redemption) {
                $redemption->update([
                    'status' => CouponRedemption::STATUS_RELEASED,
                    'released_at' => now(),
                    'release_reason' => substr($reason, 0, 40),
                ]);

                $this->syncUsedCount($redemption->coupon);
            }
        });
    }

    /**
     * Refresh the denormalised counter the admin UI reads. The redemptions
     * table stays authoritative; this column exists so coupon listings do not
     * have to aggregate on every render.
     */
    public function syncUsedCount(?Coupon $coupon): void
    {
        if ($coupon === null) {
            return;
        }

        $coupon->forceFill(['used_count' => $this->consumedCount($coupon)])->save();
    }
}
