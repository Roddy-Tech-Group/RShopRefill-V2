<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time the rate limiter decides a customer should be held back, it writes
 * a row here, whether or not enforcement is switched on.
 *
 * The limiter previously ran blind: it could refuse a customer at checkout and
 * leave no record at all, so there was no way to answer "how often does this
 * fire, and is it hitting card testers or paying customers?". Shipping a
 * limiter without that answer is how you lose revenue invisibly, which is why
 * enforcement now defaults off and this table fills up first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_backoff_events', function (Blueprint $table) {
            $table->id();

            // The identity the limit was computed against (user:1, guest:ip:uuid).
            $table->string('identifier')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_line')->index();

            // Which counter tripped: declines (a payment was submitted and
            // refused) or no_shows (Pay tapped, no method ever engaged).
            $table->string('signal', 20)->index();

            // trusted / known / guest, the allowance band applied.
            $table->string('tier', 20);

            $table->unsignedInteger('decline_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->unsignedInteger('wait_seconds')->default(0);

            // False while running in shadow: the customer was let through and
            // this row is the only trace. True once enforcement is enabled.
            $table->boolean('enforced')->default(false)->index();

            $table->string('customer_ip')->nullable();
            $table->string('device_uuid')->nullable()->index();

            $table->timestamps();

            $table->index(['created_at', 'enforced']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_backoff_events');
    }
};
