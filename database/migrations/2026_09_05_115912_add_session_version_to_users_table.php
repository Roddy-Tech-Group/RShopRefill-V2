<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-user session generation counter. Every login records the current
 * value in its session; "sign out everywhere" bumps it, and the middleware
 * refuses any session carrying an older value. Works identically on the
 * database, redis and file session drivers, unlike deleting rows from the
 * `sessions` table, which production (redis) silently ignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('session_version')->default(0)->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_version');
        });
    }
};
