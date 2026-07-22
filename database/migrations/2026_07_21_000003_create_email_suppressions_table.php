<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('reason', 20); // bounce, complaint
            $table->string('provider', 30)->default('resend');
            $table->timestamp('suppressed_at');
            $table->json('metadata')->nullable();

            $table->unique(['email', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
