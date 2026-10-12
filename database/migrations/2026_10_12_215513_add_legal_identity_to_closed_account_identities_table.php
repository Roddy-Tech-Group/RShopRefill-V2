<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verified legal identity of a closed account that moved money, moved into
 * the compliance drawer at closure so it lives on the drawer's retention clock
 * and not on the verification rows. Encrypted like the rest of the drawer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('closed_account_identities', function (Blueprint $table) {
            $table->text('legal_name')->nullable()->after('country');
            $table->text('date_of_birth')->nullable()->after('legal_name');
        });
    }

    public function down(): void
    {
        Schema::table('closed_account_identities', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'date_of_birth']);
        });
    }
};
