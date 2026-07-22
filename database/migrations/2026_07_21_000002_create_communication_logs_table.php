<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Routing
            $table->string('mail_domain', 30);
            $table->string('category', 50);
            $table->string('channel', 20);

            // Content
            $table->string('recipient');
            $table->string('sender');
            $table->string('subject', 500)->nullable();
            $table->string('template_key', 100)->nullable();

            // Delivery
            $table->string('status', 20)->default('queued');
            $table->string('provider', 30);
            $table->string('provider_message_id')->nullable();
            $table->string('priority', 20)->default('normal');

            // Traceability
            $table->string('correlation_id', 100)->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();

            // Timing
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();

            // Retry mechanics
            $table->unsignedTinyInteger('attempt_count')->default(1);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();

            // Indexes for performance
            $table->index('status');
            $table->index('category');
            $table->index('correlation_id');
            $table->index('provider_message_id');
            $table->index('idempotency_key');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
