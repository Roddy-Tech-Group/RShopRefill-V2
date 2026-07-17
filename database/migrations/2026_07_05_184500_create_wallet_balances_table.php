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
        Schema::create('wallet_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->index();
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('locked_balance')->default(0); // For DLQ/Pending Holds
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_balances');
    }
};
