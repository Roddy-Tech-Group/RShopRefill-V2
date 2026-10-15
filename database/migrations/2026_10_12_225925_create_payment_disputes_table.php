<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disputes and chargebacks raised against our payments at a gateway. One row
 * per dispute, keyed by the gateway's own dispute id, updated as the gateway
 * moves it along (a repeat notice never makes a second row). Written by the
 * dispute webhooks and by the hourly `disputes:sync` that reads each
 * gateway's chargeback list, so a lost webhook never hides one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20);
            $table->string('gateway_dispute_id', 100);
            // Our payment, when one of the references the gateway gave matches.
            $table->uuid('payment_attempt_id')->nullable()->index();
            $table->uuid('order_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained();
            // The payment reference the gateway named, kept even when it
            // matched nothing of ours so an admin can trace it by hand.
            $table->string('gateway_payment_reference', 120)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('reason')->nullable();
            $table->string('stage', 40)->nullable();
            // The gateway's word for where it stands, and ours: open | won | lost.
            $table->string('gateway_status', 40);
            $table->string('status', 10)->default('open');
            // dateTime, not timestamp: MySQL gives the first NOT-NULL timestamp
            // column an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('due_at')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('deadline_alerted_at')->nullable();
            $table->string('source', 20); // webhook | sync
            $table->json('last_payload')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_dispute_id']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
    }
};
