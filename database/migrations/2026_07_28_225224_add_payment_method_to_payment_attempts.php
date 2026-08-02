<?php

use App\Domain\Payment\Support\PaymentMethodNormaliser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record the customer-facing payment method on every payment attempt.
 *
 * `gateway` only ever stores the processor (flutterwave / nowpayments / wallet),
 * and `payment_sessions.session_type` is derived from that same processor — so a
 * Nigerian bank transfer and an Apple Pay charge are both filed as "card" today.
 * That makes it impossible to answer "are cards failing?", or to know which
 * methods carry enough volume to be worth an independent kill switch.
 *
 * The column is nullable on purpose: an attempt can be created before the
 * customer has chosen a method (wallet funding picks it on the funding sheet,
 * after the session already exists), and historical Flutterwave rows have no
 * recoverable method at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Appended rather than positioned after `gateway`. Column order is
            // cosmetic, but an ALTER that inserts mid-table can force a full
            // table rebuild — and a rebuild on payment_attempts means blocking
            // writes to the table every in-flight payment needs.
            $table->string('payment_method', 32)->nullable()->index();
        });

        // Backfill only what is knowable. crypto/nowpayments and wallet attempts
        // are 1:1 with their method, so these are facts rather than guesses.
        DB::table('payment_attempts')
            ->whereIn('gateway', ['crypto', 'nowpayments'])
            ->update(['payment_method' => 'crypto']);

        DB::table('payment_attempts')
            ->where('gateway', 'wallet')
            ->update(['payment_method' => 'wallet']);

        // Flutterwave attempts that reached verification carry the real method
        // in `data.payment_type`. Recover those; leave the rest NULL. Chunked so
        // the backfill never holds a long write lock on a large table.
        DB::table('payment_attempts')
            ->where('gateway', 'flutterwave')
            ->where(function ($query) {
                $query->whereNotNull('verification_payload')
                    ->orWhereNotNull('webhook_payload');
            })
            ->chunkById(500, function ($attempts) {
                foreach ($attempts as $attempt) {
                    $method = PaymentMethodNormaliser::fromGatewayPayload(
                        json_decode((string) $attempt->verification_payload, true) ?: null
                    ) ?? PaymentMethodNormaliser::fromGatewayPayload(
                        json_decode((string) $attempt->webhook_payload, true) ?: null
                    );

                    if ($method !== null) {
                        DB::table('payment_attempts')
                            ->where('id', $attempt->id)
                            ->update(['payment_method' => $method]);
                    }
                }
            });

        // Anything still NULL is honest: an abandoned attempt that never told us
        // how it would have been paid. Defaulting those to 'card' would launder a
        // guess into a fact that later analysis, and later kill switches, trust.
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex(['payment_method']);
            $table->dropColumn('payment_method');
        });
    }
};
