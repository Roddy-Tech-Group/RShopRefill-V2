<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_recovery_id')->constrained('transaction_recoveries')->cascadeOnDelete();
            $table->string('action'); // 'opened_email', 'clicked_link', 'provided_feedback', 'retried_payment'
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recovery_attempts');
    }
};
