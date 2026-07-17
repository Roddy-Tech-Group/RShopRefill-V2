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
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->string('currency', 3);
            $table->string('idempotency_key')->unique()->nullable();
            $table->enum('status', ['pending', 'settled', 'reversed', 'failed'])->default('settled');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_journals');
    }
};
