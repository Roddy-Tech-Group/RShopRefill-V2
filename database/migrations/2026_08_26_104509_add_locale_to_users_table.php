<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signed-in customer's language preference.
 *
 * Nullable on purpose, and NOT defaulted to 'en': null means "never chose",
 * which is what lets the middleware fall through to the browser's
 * Accept-Language on a first visit. A default of 'en' would make every existing
 * account look like it had actively picked English and pin it there.
 *
 * Two characters because config/localization.php only ever holds ISO 639-1
 * codes; the middleware validates against that list before anything is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 2)->nullable()->after('theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'locale')) {
                $table->dropColumn('locale');
            }
        });
    }
};
