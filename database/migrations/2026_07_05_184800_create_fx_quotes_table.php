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
        Schema::create('fx_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary(); // quote_id
            $table->string('provider_reference')->nullable();
            $table->string('source_currency', 3);
            $table->string('destination_currency', 3);
            $table->bigInteger('source_amount');
            $table->bigInteger('destination_amount');
            $table->bigInteger('provider_destination_amount');
            $table->bigInteger('system_revenue');
            $table->decimal('rate', 18, 8);
            $table->enum('status', ['created', 'reserved', 'consumed', 'expired'])->default('created');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            
            // Index for fast lookup by UUID and Status
            $table->index(['id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fx_quotes');
    }
};
