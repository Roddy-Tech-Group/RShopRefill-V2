<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks when a customer finished the details every account needs: country,
 * phone and gender, plus their acceptance of the terms.
 *
 * Credentials signup collects all of that in the register wizard, so those
 * accounts are complete the moment they are created. Google sign-in cannot:
 * Google returns only a name, an email and a picture, so a brand-new Google
 * account lands with those three columns null and nothing ever asks for them.
 * A null here routes the customer through the completion step exactly once.
 *
 * Every account that already exists is backfilled as complete. Nobody who is
 * already using the platform gets stopped by a gate that did not exist when
 * they signed up; the requirement starts with the next new account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable, so MySQL never attaches the implicit
            // DEFAULT CURRENT_TIMESTAMP ON UPDATE that a leading NOT NULL
            // timestamp column would inherit.
            $table->timestamp('profile_completed_at')->nullable()->after('country');
        });

        DB::table('users')->whereNull('profile_completed_at')->update([
            'profile_completed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_completed_at');
        });
    }
};
