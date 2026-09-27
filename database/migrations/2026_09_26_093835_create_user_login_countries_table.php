<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which countries an account has signed in from, and which of those it has
 * proved.
 *
 * Two jobs in one row, because they are the same fact seen twice:
 *
 *  - `last_seen_at` is what the login country check compares against. Asking
 *    "has this account been in a different country in the last few hours" is a
 *    query over this table, which is why the check does not read the device
 *    registry: the device row has already been overwritten with the current
 *    login by the time risk is scored.
 *
 *  - `proven_at` is why a customer is asked at most once per country. A
 *    proven country is one the customer demonstrably reaches: they either
 *    passed the emailed code from it, or signed in from it with nothing else
 *    suspicious. Roughly a fifth of our devices resolve to the United States
 *    on a platform serving Cameroon and Nigeria, which is VPN exit traffic,
 *    and without this column every one of those customers would be challenged
 *    on every session for as long as they keep using their VPN.
 *
 * Proof is not permanent: it expires, so an account cannot accumulate a
 * standing list of countries that silences the check for life.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->string('country_name')->nullable();

            // dateTime, not timestamp: MariaDB gives the first NOT NULL
            // timestamp column an implicit ON UPDATE CURRENT_TIMESTAMP, which
            // would quietly rewrite these the next time any column changed.
            $table->dateTime('last_seen_at');
            $table->dateTime('proven_at')->nullable();

            $table->string('last_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'country_code']);
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_countries');
    }
};
