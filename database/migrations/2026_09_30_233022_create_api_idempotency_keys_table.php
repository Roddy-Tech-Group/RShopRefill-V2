<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the answer we already gave, so a retried request cannot be performed
 * twice.
 *
 * The problem this exists for: the customer taps Pay, we create the order and
 * take the money, and the reply never reaches the phone because the network
 * dropped. The app sees a timeout, so it retries with the only information it
 * has, and without this table we do the whole thing again. On a browser on wifi
 * this is rare. On mobile data it is routine.
 *
 * The app sends an Idempotency-Key header, one value per intent (per tap, not per
 * HTTP attempt). The first request claims the row; a retry finds it and gets the
 * original answer back rather than a second order.
 *
 * Shaped after the convention Stripe made standard, with three deliberate
 * choices:
 *
 *  - Keys are scoped PER USER. One customer's key can never collide with, or read
 *    back, another's.
 *  - The request body is fingerprinted. Re-using a key with different details is
 *    refused rather than answered with the old result, because that would hide a
 *    client bug behind a stale success.
 *  - locked_until exists so a request that dies mid-flight (worker killed, PHP
 *    timeout) releases its key instead of wedging that intent forever.
 *
 * Every date column is dateTime(), not timestamp(). MariaDB gives the first NOT
 * NULL TIMESTAMP column an implicit ON UPDATE CURRENT_TIMESTAMP, which would move
 * expires_at forward on every save and make these rows immortal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // sha256 of the key the client sent. Hashed for the same reason tokens
            // are: these identify money-moving intents, and a database dump should
            // not hand over a set of replayable request tickets.
            $table->char('key_hash', 64);

            // The endpoint this key was spent on. The same key aimed at a
            // different endpoint is a client bug, not a retry, and is refused.
            $table->string('method', 10);
            $table->string('path');

            // sha256 of the canonicalised request body. Catches a key re-used for
            // a different amount, which is the dangerous version of the mistake.
            $table->char('request_hash', 64);

            // 'in_progress' while the first attempt is running, 'completed' once
            // there is an answer worth replaying.
            $table->string('status', 20)->default('in_progress');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();

            // While this is in the future, a concurrent request with the same key
            // is told the first one is still running rather than being allowed to
            // start a second. Once it passes, a retry may take the row over,
            // because the original attempt evidently died.
            $table->dateTime('locked_until')->nullable();
            $table->dateTime('completed_at')->nullable();

            // 24 hours, the same window Stripe uses. Long enough to cover a
            // customer whose phone was offline, short enough that this table
            // stays small.
            $table->dateTime('expires_at')->index();

            $table->timestamps();

            // The atomic claim. Two simultaneous taps race to insert this row and
            // exactly one wins; the loser reads the winner's row. This unique
            // index IS the concurrency control, which is why it is not merely an
            // optimisation.
            $table->unique(['user_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
