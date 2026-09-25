<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per eSIM we sold: the record behind the admin's Manage eSIMs page.
 *
 * An order item can carry several eSIMs (quantity > 1), so the key is the
 * (order item, ICCID) pair, not the item. A top-up adds data to an existing
 * eSIM and never creates a row. Status and data come from the supplier's
 * usage endpoint; activated_at is when our sync first saw the eSIM active,
 * because the supplier reports no activation time.
 *
 * Dates are dateTime(), never timestamp(): MySQL silently rewrites the first
 * NOT NULL timestamp column to now() on every update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esims', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('iccid', 32);
            $table->string('package_name')->nullable();
            $table->string('package_code')->nullable();
            $table->string('status', 20)->default('inactive');
            $table->string('provider_status', 20)->nullable();
            $table->unsignedInteger('data_total_mb')->nullable();
            $table->unsignedInteger('data_remaining_mb')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->dateTime('purchased_at');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            // The refund runs in two steps: requested (a claim to the supplier,
            // no money moves), then completed once the supplier credits us (the
            // customer's wallet is credited) or rejected (nothing moves).
            $table->string('refund_status', 20)->nullable();
            $table->decimal('refund_amount', 16, 4)->nullable();
            $table->string('refund_currency', 10)->nullable();
            $table->string('refund_reason', 60)->nullable();
            $table->text('refund_note')->nullable();
            $table->string('refund_claim_reference')->nullable();
            $table->foreignId('refund_requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('refund_requested_at')->nullable();
            $table->foreignId('refund_resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('refund_resolved_at')->nullable();
            $table->text('refund_resolution_note')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->foreignId('order_refund_id')->nullable()->constrained('order_refunds')->nullOnDelete();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('sync_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'iccid']);
            $table->index('iccid');
            $table->index(['status', 'purchased_at']);
            $table->index(['provider', 'last_synced_at']);
            $table->index(['refund_status', 'refund_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esims');
    }
};
