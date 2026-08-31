<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set by retention:enforce once a closed account's financial records have
 * passed their statutory window and been removed, so the sweep never
 * re-examines the same tombstone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('financial_records_purged_at')->nullable()->after('anonymised_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('financial_records_purged_at');
        });
    }
};
