<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The regulator-facing identity of a closed account. When an account that has
 * moved money is anonymised, the name, email, phone, country and identity
 * verification reference are copied here (encrypted at rest) so the "who was
 * account #N" question can still be answered for the five years the
 * anti-money-laundering rules require. Nothing reads this table at sign-in
 * or sign-up; it exists for compliance lookups only and is purged on schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closed_account_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->text('name');
            $table->text('email');
            $table->text('phone')->nullable();
            $table->string('country', 8)->nullable();
            $table->string('kyc_provider', 32)->nullable();
            $table->string('kyc_reference', 100)->nullable();
            $table->dateTime('closed_at');
            $table->dateTime('retain_until')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closed_account_identities');
    }
};
