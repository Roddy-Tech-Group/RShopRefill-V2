<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `expires_at` was created as the table's first NOT-NULL TIMESTAMP column, so
 * MySQL (with explicit_defaults_for_timestamp off) implicitly gave it
 * DEFAULT CURRENT_TIMESTAMP **ON UPDATE CURRENT_TIMESTAMP** — every status
 * update (feedback received, recovered, cancelled) silently reset the
 * recovery link's expiry to "now", instantly killing the link. DATETIME
 * carries no auto-update semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_recoveries', function (Blueprint $table) {
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_recoveries', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable(false)->change();
        });
    }
};
