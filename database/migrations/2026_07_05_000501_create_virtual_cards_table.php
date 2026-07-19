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
        Schema::create('virtual_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('maplerad');
            $table->string('provider_card_id')->unique()->nullable();
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['PENDING', 'ACTIVE', 'FROZEN', 'TERMINATED'])->default('PENDING');
            $table->string('masked_pan', 20)->nullable();
            $table->string('name_on_card')->nullable();
            $table->string('expiry_month', 2)->nullable();
            $table->string('expiry_year', 4)->nullable();
            $table->string('brand')->nullable(); // e.g. VISA, MASTERCARD
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('virtual_cards');
    }
};
