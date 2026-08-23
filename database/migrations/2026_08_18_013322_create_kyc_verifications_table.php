<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-era KYC attempts (Smile ID web sessions). One row per verification
 * attempt: minted at session start (pending), resolved by the webhook or an
 * admin "check with provider" sync. This is what the admin customer page shows
 * for identity verification, and it stores the provider job reference so a
 * stuck job can be reconciled against Smile's job-status API. The legacy
 * kyc_submissions table (manual document uploads) stays for historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('smile');
            // The provider job id ('kyc_{user}_{random}') webhooks echo back.
            $table->string('reference', 100)->unique();
            // Smile web-integration product: doc_verification | biometric_kyc.
            $table->string('product', 40)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->string('result_code', 20)->nullable();
            $table->string('result_text')->nullable();
            // dateTime, not timestamp: MySQL gives the first NOT-NULL timestamp
            // column an implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};
