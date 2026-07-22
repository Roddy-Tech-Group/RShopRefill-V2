<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-terminal frontend telemetry (JS exceptions, offline blips, slow
 * connections observed during a payment). Kept out of payment_outcomes so
 * environmental noise never distorts conversion metrics — only terminal
 * events fail a session and produce an outcome row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_telemetry_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 64)->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_telemetry_events');
    }
};
