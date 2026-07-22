<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Master Switches
            $table->boolean('email_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);

            // Category Matrix (Wallet)
            $table->boolean('wallet_email')->default(true);
            $table->boolean('wallet_push')->default(true);

            // Category Matrix (Orders)
            $table->boolean('orders_email')->default(true);
            $table->boolean('orders_push')->default(true);

            // Category Matrix (Transactions)
            $table->boolean('transactions_email')->default(true);
            $table->boolean('transactions_push')->default(true);

            // Category Matrix (Product Updates / Notifications)
            $table->boolean('product_updates_email')->default(true);
            $table->boolean('product_updates_push')->default(true);

            // Category Matrix (Marketing)
            $table->boolean('marketing_email')->default(true);
            $table->boolean('marketing_push')->default(true);

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_preferences');
    }
};
