<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One immutable row per RiskEngine::assess() call: the reproducible, auditable
 * record of a risk decision an acquirer expects and a chargeback dispute needs.
 * Never edited after write; corrections are new rows. Its own IP/device columns
 * also serve as the velocity store the IP/device velocity signals read back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Nullable for guest checkout (no account yet).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // 'order' | 'funding' | 'pay' | 'login'
            $table->string('context')->index();

            // The thing assessed (order/funding/session); nullable when the
            // subject is created after the assessment (e.g. pre-order checkout).
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->unsignedInteger('score');
            $table->string('band');                 // LOW|MEDIUM|HIGH|CRITICAL
            $table->string('outcome');              // allow|step_up|review|deny (computed)
            $table->boolean('enforced')->default(false); // acted on, or shadow-only

            $table->string('ip_address', 45)->nullable();
            $table->string('device_uuid')->nullable();

            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->string('currency', 3)->nullable();

            $table->string('policy_version')->nullable();
            $table->string('correlation_id')->nullable();

            // Append-only: only ever created, so a created_at timestamp is enough.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['device_uuid', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['band', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
    }
};
