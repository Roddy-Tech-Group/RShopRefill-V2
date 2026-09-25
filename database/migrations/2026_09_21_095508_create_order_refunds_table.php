<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The refund ledger: one immutable row per refund, so the Finances page can
 * record what was given back in the month it was given back.
 *
 * Before this there was no refunds table. A refund was a tag => amount entry
 * in orders.metadata with no date, and the Finances page read wallet refund
 * credits only, so a card refund never appeared and a full refund made the
 * whole order vanish from the month it was sold in.
 *
 * Two kinds, because only one of them touches revenue:
 *   customer_refund      money back for goods the customer received. Reverses
 *                        revenue (revenue_reversed_usd) in refunded_at's month.
 *   fulfilment_reversal  money back for an item that never delivered. That
 *                        item was never counted as revenue, so nothing reverses.
 *
 * A supplier credit (Airalo crediting its account for a refunded eSIM) is a
 * separate event with its own date, because it lands when the supplier grants
 * it, not when we refund the customer, and the monthly supplier invoice
 * follows that date.
 *
 * Dates are dateTime(), not timestamp(): MySQL rewrites the first NOT NULL
 * timestamp column to now() on every update, which would silently move a
 * refund into whatever month someone last touched the row.
 *
 * Cascades from orders like every other financial child (items, payments,
 * wallet records), so retention:enforce can still purge a closed customer's
 * financial records when the legal period ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 30);
            $table->string('tag');

            // What the customer got back, in the order's own currency.
            $table->decimal('amount', 16, 4);
            $table->string('currency', 10);

            // The revenue this refund unwinds, in USD at the order's own rate.
            // Zero for a fulfilment reversal.
            $table->decimal('revenue_reversed_usd', 16, 4)->default(0);

            // Per-item split of revenue_reversed_usd for the provider and
            // category breakdowns: [{order_item_id, provider, category_id, revenue_reversed_usd}].
            $table->json('allocations')->nullable();

            $table->string('gateway', 30)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('refunded_at');

            $table->string('supplier_credit_status', 20)->default('not_applicable');
            $table->decimal('supplier_credit_usd', 16, 4)->nullable();
            $table->string('supplier_credit_reference')->nullable();
            $table->string('supplier_credit_reason', 60)->nullable();
            $table->dateTime('supplier_credited_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'tag']);
            $table->index(['kind', 'refunded_at']);
            $table->index(['supplier_credit_status', 'supplier_credited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
