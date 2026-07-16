<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid')->unique();
            $table->string('device_name')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->string('device_type')->nullable(); // Mobile, Desktop, Tablet
            $table->string('ip_address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('fingerprint')->index();
            $table->timestamp('trusted_since')->nullable();
            $table->unsignedTinyInteger('trust_score')->default(20); // e.g., 20-95
            $table->unsignedInteger('current_session_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
