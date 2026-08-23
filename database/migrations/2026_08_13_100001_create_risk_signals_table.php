<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual signals that fired for an assessment, with their weight and
 * PII-safe detail. Together with the parent assessment's policy_version this is
 * what makes a decision fully reproducible and explainable in the admin surface
 * and in dispute evidence. Immutable, like the assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('risk_assessment_id')
                ->constrained('risk_assessments')
                ->cascadeOnDelete();

            $table->string('code')->index();
            $table->integer('weight');
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_signals');
    }
};
