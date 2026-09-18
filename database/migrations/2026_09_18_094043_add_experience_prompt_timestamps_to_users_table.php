<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the experience prompt last asked this customer, and when they answered.
 * The rule is once a day until answered, then never again; both clocks live on
 * the account rather than in a browser so they hold across devices and cache
 * flushes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('experience_prompted_at')->nullable()->after('phone_verified_at');
            $table->timestamp('experience_rated_at')->nullable()->after('experience_prompted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['experience_prompted_at', 'experience_rated_at']);
        });
    }
};
