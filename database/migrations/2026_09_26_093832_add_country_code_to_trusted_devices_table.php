<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device registry has stored a country NAME since July, resolved from a
 * provider whose spellings are its own ("The Netherlands"). Names are fine to
 * show a customer and useless to compare, because swapping provider silently
 * rewrites them and a rule comparing two logins would read that as travel.
 *
 * The ISO 3166-1 alpha-2 code is stable across providers, so it is added
 * alongside the name: the code decides, the name displays. Existing rows keep
 * their name and gain a code the next time the device is seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable()->after('country')->index();
        });
    }

    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropColumn('country_code');
        });
    }
};
