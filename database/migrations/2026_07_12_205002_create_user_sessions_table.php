<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trusted_device_id')->nullable()->constrained('trusted_devices')->nullOnDelete();
            $table->string('laravel_session_id')->nullable()->index(); // Links to Laravel's sessions table if applicable
            $table->string('access_token')->nullable()->index(); // Hash of the API token
            $table->string('refresh_token')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('risk_level')->default('LOW'); // LOW, MEDIUM, HIGH, CRITICAL
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
