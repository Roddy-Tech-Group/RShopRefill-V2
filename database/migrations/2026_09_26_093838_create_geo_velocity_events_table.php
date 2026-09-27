<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time the login country check sees two countries too close together.
 *
 * The rule it replaces scored a number into a session row and left no trace of
 * why, so the only honest answer to "who did this challenge, and was it right"
 * was a shrug. This is the same discipline as spending_limit_events: the
 * decision is recorded whether or not it acted, so `geo:report` can show which
 * customers were asked for a code, which were let through on a country they had
 * already proved, and whether any of it ever caught anything real.
 *
 * It is a log, never an input: nothing reads it to make a decision, and a
 * failed write is swallowed so recording can never break a login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_velocity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->char('from_country_code', 2);
            $table->char('to_country_code', 2);
            $table->string('from_country_name')->nullable();
            $table->string('to_country_name')->nullable();

            $table->unsignedInteger('minutes_apart');
            $table->unsignedSmallInteger('window_hours');

            // Whether the customer was actually asked for a code. False means
            // the destination was a country they had already proved.
            $table->boolean('challenged')->default(false);
            $table->boolean('destination_proven')->default(false);

            $table->string('ip_address', 45)->nullable();
            $table->string('device_uuid')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_velocity_events');
    }
};
