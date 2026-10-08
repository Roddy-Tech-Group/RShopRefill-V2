<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment switchboard: one row per currency and payment method.
 *
 * Each row says whether we offer the method in that currency, where its tile
 * sits on the payment screens, and which gateways may take it, in order. The
 * first gateway that is switched on, able to charge it, and approved for the
 * cart takes the payment; the rest are its fallbacks. Edited from Management >
 * Gateway settings, so moving a rail between gateways is a decision, not a
 * deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_routes', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3);
            $table->string('method', 32);
            $table->boolean('is_offered')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('gateways');
            // Why a row is switched off (Apple Pay waiting on the live account,
            // say), so the next person does not switch it back on blind.
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_routes');
    }
};
