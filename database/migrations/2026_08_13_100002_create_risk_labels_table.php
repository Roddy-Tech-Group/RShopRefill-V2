<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The feedback loop: ground-truth outcomes (chargeback, confirmed fraud, false
 * positive, good) attached to a user/subject. Chargeback + dispute webhooks and
 * admin actions write here; the CONFIRMED_FRAUD_LABEL / CHARGEBACK_HISTORY
 * signals read from here so the engine learns from what actually happened
 * instead of ossifying. Created in Stage 1 so labels can start accruing early;
 * the writers (webhooks/admin) and the chargeback signal land in Stage 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            // chargeback | confirmed_fraud | false_positive | good
            $table->string('label')->index();

            // webhook | admin | dispute
            $table->string('source');

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_labels');
    }
};
