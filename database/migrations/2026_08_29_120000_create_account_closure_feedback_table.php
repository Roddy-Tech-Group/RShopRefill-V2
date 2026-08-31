<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional "why are you leaving" answers captured at account closure. Keyed by
 * user id only, so it stays useful as product signal after the account is
 * anonymised without identifying anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_closure_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->json('reasons');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_closure_feedback');
    }
};
