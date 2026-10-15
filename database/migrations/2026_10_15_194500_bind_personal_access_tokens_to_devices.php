<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties every app token to what the website already uses to end a sign-in, so
 * the app is signed out by the same actions that sign a browser out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // The phone the token was issued to. Removing that device in the
            // security centre withdraws its trust, and the token with it.
            $table->foreignId('trusted_device_id')->nullable()->constrained('trusted_devices')->cascadeOnDelete();

            // The security centre's record of this sign-in, so "sign out
            // everywhere" and "sign out this device" reach the app as well.
            $table->foreignId('user_session_id')->nullable()->constrained('user_sessions')->cascadeOnDelete();

            // A sha256 of the password hash the token was issued under: a
            // password change or reset ends it, as AuthenticateSession does
            // for a browser.
            $table->string('password_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trusted_device_id');
            $table->dropConstrainedForeignId('user_session_id');
            $table->dropColumn('password_fingerprint');
        });
    }
};
