<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manual review queue. One row per action the engine held for a human: it
 * links back to the immutable assessment that triggered it, tracks the admin
 * decision lifecycle (pending -> approved/rejected/escalated), and carries the
 * clearance window that an approval grants so the customer's retry can proceed.
 *
 * assigned_admin_id / decided_by reference the admins table (admins live outside
 * the users table), so they are plain unsigned ints with no FK, matching how the
 * rest of the app records admin actors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context');                       // order | funding | pay
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('score')->default(0);
            $table->string('band')->nullable();
            $table->json('reason_codes')->nullable();

            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();

            // When an approval's clearance expires. dateTime (not timestamp): a
            // nullable expiry must never be rewritten to now() on update by
            // MySQL's implicit ON UPDATE on the first timestamp column.
            $table->dateTime('cleared_until')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'context', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_reviews');
    }
};
