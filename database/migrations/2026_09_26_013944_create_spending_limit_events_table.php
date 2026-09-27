<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time a customer reaches a spending cap, recorded.
 *
 * The caps have run in shadow since they shipped: the gate worked out the
 * refusal it would issue, wrote one line to the application log, and let the
 * money through. Nobody reads that log, so there was no way to answer the
 * only question that matters before enforcing: who would this have stopped?
 *
 * A row is written whether the cap was enforced or merely observed, so the
 * table keeps its meaning after the flag flips: `enforced = false` is a
 * customer who was let through, `true` is one who was refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_limit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'order' or 'funding': what the customer was trying to do.
            $table->string('context', 20)->index();

            // Which rolling window tripped: daily, weekly or monthly.
            $table->string('limit_window', 20)->index();

            // The cap, the money in already counted against it, and what the
            // customer was trying to add. All settled USD, as the meter uses.
            $table->decimal('limit_usd', 16, 4);
            $table->decimal('money_in_usd', 16, 4);
            $table->decimal('amount_usd', 16, 4);

            // Which ladder applied when this fired. A customer who verifies
            // later must not make a past event unreadable.
            $table->boolean('kyc_verified')->default(false);

            // False while the caps run in shadow: the customer was let
            // through and this row is the only trace. True once enforcement
            // is on and the action was actually refused.
            $table->boolean('enforced')->default(false)->index();

            $table->timestamps();

            $table->index(['created_at', 'enforced']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_limit_events');
    }
};
