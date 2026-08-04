<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recovery intelligence (additive only).
     *
     * The recovery record previously knew only that a payment failed, never
     * WHY, so every email said the same thing to a customer whose card
     * expired and one whose browser blocked the popup. It also had no way to
     * tell a click from an actual recovered payment.
     *
     * failure_reason_code: the classifier's verdict, snapshotted at send time
     * so the email copy and later analytics agree even if the outcome row is
     * reclassified afterwards.
     *
     * clicked_at / retried_at / recovered_at: the funnel, in timestamps.
     * recovered_at is set ONLY when a payment actually confirms — a click is
     * not revenue, and the old code marked recoveries "recovered" on click.
     *
     * All datetimes use dateTime(), never timestamp(): on MySQL the first
     * NOT-NULL timestamp column silently gains ON UPDATE CURRENT_TIMESTAMP
     * (this table was bitten by exactly that, fixed in the 2026_07_22
     * migration) and every one of these would be rewritten on each save.
     */
    public function up(): void
    {
        Schema::table('transaction_recoveries', function (Blueprint $table) {
            $table->foreignId('payment_outcome_id')->nullable()->after('payment_session_id')
                ->constrained()->nullOnDelete();
            $table->string('failure_reason_code')->nullable()->index()->after('customer_email');
            $table->dateTime('clicked_at')->nullable()->after('status');
            $table->dateTime('retried_at')->nullable()->after('clicked_at');
            $table->dateTime('recovered_at')->nullable()->after('retried_at');
            $table->dateTime('reminder_sent_at')->nullable()->after('recovered_at');
            // Which session finally paid — the audit trail behind every
            // "recovered" claim, so attributed revenue can be re-derived.
            $table->uuid('recovered_payment_session_id')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_recoveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_outcome_id');
            $table->dropColumn([
                'failure_reason_code',
                'clicked_at',
                'retried_at',
                'recovered_at',
                'reminder_sent_at',
                'recovered_payment_session_id',
            ]);
        });
    }
};
