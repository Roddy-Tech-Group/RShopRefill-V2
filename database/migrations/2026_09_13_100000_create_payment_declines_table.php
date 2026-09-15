<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per card the gateway refused, pulled from the gateway after a
 * checkout popup closes.
 *
 * Flutterwave sends no webhook for a failed inline charge and lets a customer
 * try several cards under one reference, so until this table existed every
 * refused card ended up filed as "customer walked away". Three months of
 * production traffic held six hundred refusals, two thirds of them fraud
 * verdicts, and none were recorded anywhere the rate limiter, the risk engine
 * or the insights dashboard could see. This is the record they now read.
 *
 * No full card number is ever stored: the fingerprint is a keyed hash of the
 * first six and last four digits, enough to say "same card" and nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_declines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('payment_session_id')->nullable()->index();
            $table->uuid('payment_attempt_id')->nullable()->index();
            $table->string('product_line')->index();

            $table->string('gateway', 30);
            $table->string('gateway_transaction_id', 64)->nullable();
            $table->string('tx_ref')->index();

            // How they tried to pay, verbatim from the gateway: card, banktransfer,
            // mobilemoney*, ussd, account. Only cards carry a fingerprint, so this
            // is the only way to tell a refused bank transfer from a refused card
            // in the reports, and the only way to see a method failing in bulk.
            $table->string('payment_method', 30)->nullable()->index();

            $table->string('card_fingerprint', 64)->nullable()->index();
            $table->string('card_brand', 40)->nullable();
            $table->string('card_issuer_country', 2)->nullable();

            // The gateway's verbatim verdict, and the class we filed it under:
            // fraud (weighs fully), soft (weighs half), ignored (our fault or a
            // card the gateway cannot process, weighs nothing).
            $table->string('verdict')->nullable();
            $table->string('verdict_class', 10)->index();
            $table->string('reason_code', 60)->nullable();

            $table->decimal('amount', 18, 4)->default(0);
            $table->string('currency', 3)->nullable();

            $table->string('customer_ip', 45)->nullable()->index();
            $table->string('device_uuid')->nullable()->index();

            $table->dateTime('declined_at')->index();

            $table->timestamps();

            $table->unique(['gateway', 'gateway_transaction_id']);
            $table->index(['user_id', 'declined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_declines');
    }
};
