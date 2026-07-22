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
        Schema::create('payment_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('payment_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('product_line')->index();
            $table->string('outcome')->index();

            $table->string('provider')->nullable()->index();
            $table->string('payment_method')->nullable();

            $table->string('failure_stage')->nullable();
            $table->string('failure_category')->nullable()->index();
            $table->string('failure_reason_code')->nullable();

            $table->unsignedInteger('consecutive_failure_count')->default(1);
            $table->unsignedInteger('session_duration_seconds')->nullable();

            $table->string('device_uuid')->nullable()->index();
            $table->string('customer_ip')->nullable();
            $table->string('correlation_id')->nullable()->index();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['payment_session_id', 'outcome']); // Unique index on session + outcome
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_outcomes');
    }
};
