<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Failure diagnostics (additive only).
     *
     * provider_code: the gateway's verbatim verdict for this failure — a
     * Flutterwave processor_response ("Insufficient Funds"), a NOWPayments
     * status ("partially_paid") or an internal marker. Indexed so the
     * insights page can group by the gateway's own words.
     *
     * retryable: the classifier's judgement on whether asking the customer
     * to try again could ever succeed. Read by recovery-eligibility logic:
     * a stolen-card decline must never receive a "complete your payment"
     * invitation.
     */
    public function up(): void
    {
        Schema::table('payment_outcomes', function (Blueprint $table) {
            $table->string('provider_code')->nullable()->index()->after('failure_reason_code');
            $table->boolean('retryable')->nullable()->after('provider_code');
        });
    }

    public function down(): void
    {
        Schema::table('payment_outcomes', function (Blueprint $table) {
            $table->dropColumn(['provider_code', 'retryable']);
        });
    }
};
