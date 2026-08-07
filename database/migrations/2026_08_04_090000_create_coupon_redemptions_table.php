<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupons were counted with a single `used_count` column incremented the moment
 * a checkout was created. Nothing ever decremented it, so every abandoned or
 * cancelled checkout permanently consumed a redemption: a customer clicking Pay
 * ten times could exhaust a capped promotion without paying once.
 *
 * This table makes redemption a first-class record with a reservation phase, so
 * an abandoned checkout releases its slot while a live one still holds it (no
 * oversell). It also gives us an audit trail and, later, per-customer limits,
 * neither of which a counter can express.
 *
 * `used_count` survives as a denormalised cache for the admin UI, now written
 * only from confirmed redemptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            // orders.id is a uuid (see create_commerce_orchestration_tables),
            // NOT a bigint. foreignId here would silently build a column MySQL
            // can never join, and SQLite's loose typing hides it in tests.
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // reserved -> held by a live checkout, counts against max_uses
            // redeemed -> paid for, counts forever
            // released -> abandoned/cancelled, counts against nothing
            $table->string('status', 20)->default('reserved');

            $table->decimal('discount_usd', 12, 4)->default(0);

            // dateTime, NOT timestamp: MySQL silently attaches ON UPDATE
            // CURRENT_TIMESTAMP to the first NOT-NULL timestamp column in a
            // table, which would rewrite an expiry on every status change.
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('redeemed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 40)->nullable();

            $table->timestamps();

            // One redemption per coupon per order. This is what makes a replayed
            // webhook or a double confirm impossible to double-count.
            $table->unique(['coupon_id', 'order_id']);

            // Drives the availability count on every checkout.
            $table->index(['coupon_id', 'status', 'expires_at']);
        });

        $this->backfillFromPaidOrders();
    }

    /**
     * Reconstruct history from orders that actually got paid, then rewrite
     * used_count to the truth. Without this, coupons already inflated by
     * abandoned checkouts in production stay wrongly marked as used up.
     *
     * Done in PHP rather than a JSON-path UPDATE so it behaves identically on
     * MySQL and the SQLite test database.
     */
    private function backfillFromPaidOrders(): void
    {
        $now = now();
        $redeemedPerCoupon = [];

        // An order's metadata carries the coupon_id it was placed with, but
        // coupons cascade-delete with their product variant, so a historical
        // order can name a coupon that no longer exists. Those references
        // cannot be backfilled (there is no allowance left to consume) and
        // inserting them violates the foreign key, which failed this migration
        // in production. Resolve the surviving ids once rather than querying
        // per row.
        $liveCouponIds = DB::table('coupons')->pluck('id')->flip();

        DB::table('orders')
            ->select('id', 'user_id', 'metadata', 'created_at')
            ->whereNotNull('metadata')
            ->where('payment_status', 'paid')
            ->orderBy('id')
            ->chunk(500, function ($orders) use (&$redeemedPerCoupon, $now, $liveCouponIds) {
                $rows = [];

                foreach ($orders as $order) {
                    $metadata = json_decode((string) $order->metadata, true);
                    $couponId = $metadata['coupon_id'] ?? null;

                    if (! $couponId || ! $liveCouponIds->has($couponId)) {
                        continue;
                    }

                    $rows[] = [
                        'coupon_id' => $couponId,
                        'order_id' => $order->id,
                        'user_id' => $order->user_id,
                        'status' => 'redeemed',
                        'discount_usd' => $metadata['coupon_discount_usd'] ?? 0,
                        'expires_at' => null,
                        'redeemed_at' => $order->created_at,
                        'released_at' => null,
                        'release_reason' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $redeemedPerCoupon[$couponId] = ($redeemedPerCoupon[$couponId] ?? 0) + 1;
                }

                if ($rows !== []) {
                    DB::table('coupon_redemptions')->insert($rows);
                }
            });

        // Every coupon starts from zero; only the ones with real paid orders
        // above get a count back. This is the step that actually frees the
        // promotions that trial-and-error checkouts burned through.
        DB::table('coupons')->update(['used_count' => 0]);

        foreach ($redeemedPerCoupon as $couponId => $count) {
            DB::table('coupons')->where('id', $couponId)->update(['used_count' => $count]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
